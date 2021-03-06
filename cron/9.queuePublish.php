<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

$queuePublish = new RedisQueue('queuePublish');
$minute = date('Hi');

$topKillID = max(1, $mdb->findField('killmails', 'killID', [], ['killID' => -1]));

while ($minute == date('Hi')) {
    $killID = (int) $queuePublish->pop();
    if ($killID > 0 ) {
        if ($redis->get("tobefetched") > 1000 && $killID < ($topKillID - 10000)) continue;
        publish($killID);
    } else sleep(1);
}

function publish($killID)
{
    global $mdb, $redis, $imageServer, $esiServer;

    $kill = $mdb->findDoc('killmails', ['killID' => $killID]);
    $raw = Kills::getEsiKill($killID);
    unset($raw['_id']);

    $zkb = $kill['zkb'];
    $zkb['npc'] = @$kill['npc'];
    $zkb['solo'] = @$kill['solo'];
    $zkb['awox'] = @$kill['awox'];
    $zkb['esi'] = "$esiServer/latest/killmails/$killID/".$zkb['hash'].'/';
    $zkb['url'] = "https://zkillboard.com/kill/$killID/";
    $raw['zkb'] = $zkb;
    $redis->publish("killstream", json_encode($raw, true));

    $hours24 = time() - 86400;
    if ($kill['dttm']->sec < $hours24) return;

    $channels = ['none:*' => true, 'all:*' => true];
    foreach ($kill['involved'] as $involved) {
        $channels['character:' . (int) @$involved['characterID']] = true;
        $channels['corporation:' . (int) @$involved['corporationID']] = true;
        $channels['alliance:' . (int) @$involved['allianceID']] = true;
        $channels['faction:' . (int) @$involved['factionID']] = true;
        $channels['ship:' . (int) @$involved['shipTypeID']] = true;
        $channels['group:' . (int) @$involved['groupID']] = true;
    }
    $channels["system:" . $kill['system']['solarSystemID']] = true;
    $channels["constellation:" . $kill['system']['constellationID']] = true;
    $channels["region:" . $kill['system']['regionID']] = true;
    $channels["location:" . $kill['zkb']['locationID']] = true;
    if ($zkb['totalValue'] >= 10000000000) $channels['total_value:10b+'] = true;
    $channels = array_keys($channels);

    $victimInfo = $kill['involved'][0];
    Info::addInfo($victimInfo);
    if (!isset($victimInfo['characterName'])) return;
    $name = $victimInfo['characterName'];
    $name .= (strtolower(substr($name, -1))) == 's' ? "'" : "'s";
    $redisMessage = [
        'action' => 'littlekill',
        'killID' => $killID,
        'character_id' => (int) @$victimInfo['characterID'],
        'corporation_id' => (int) @$victimInfo['corporationID'],
        'alliance_id' => (int)  @$victimInfo['allianceID'],
        'ship_type_id' => (int) $victimInfo['shipTypeID'],
        'url' => "https://zkillboard.com/kill/$killID/",
        'hash' => $zkb['hash'],
            ];
    $msg = json_encode($redisMessage, JSON_UNESCAPED_SLASHES);
    foreach ($channels as $channel) {
        $redis->publish($channel, $msg);
    }
}
