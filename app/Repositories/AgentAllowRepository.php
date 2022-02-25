<?php
namespace App\Repositories;

use App\Exceptions\NexusException;
use App\Models\AgentAllow;
use App\Models\AgentDeny;

class AgentAllowRepository extends BaseRepository
{
    public function getList(array $params)
    {
        $query = AgentAllow::query();
        if (!empty($params['family'])) {
            $query->where('family', 'like', "%{$params['family']}%");
        }
        list($sortField, $sortType) = $this->getSortFieldAndType($params);
        $query->orderBy($sortField, $sortType);
        return $query->paginate();
    }

    public function store(array $params)
    {
        $this->getPatternMatches($params['peer_id_pattern'], $params['peer_id_start'], $params['peer_id_match_num']);
        $this->getPatternMatches($params['agent_pattern'], $params['agent_start'], $params['agent_match_num']);
        $model = AgentAllow::query()->create($params);
        return $model;
    }

    public function update(array $params, $id)
    {
        $this->getPatternMatches($params['peer_id_pattern'], $params['peer_id_start'], $params['peer_id_match_num']);
        $this->getPatternMatches($params['agent_pattern'], $params['agent_start'], $params['agent_match_num']);
        $model = AgentAllow::query()->findOrFail($id);
        $model->update($params);
        return $model;
    }

    public function getDetail($id)
    {
        $model = AgentAllow::query()->findOrFail($id);
        return $model;
    }

    public function delete($id)
    {
        $model = AgentAllow::query()->findOrFail($id);
        $model->denies()->delete();
        $result = $model->delete();
        return $result;
    }

    public function getPatternMatches($pattern, $start, $matchNum)
    {
        if (!preg_match($pattern, $start, $matches)) {
            throw new NexusException(sprintf('pattern: %s can not match start: %s', $pattern, $start));
        }
        $matchCount = count($matches) - 1;
        //due to old data may be matchNum > matchCount
        if ($matchNum > $matchCount && !IN_NEXUS) {
            throw new NexusException("pattern: $pattern match start: $start got matches count: $matchCount, but require $matchNum.");
        }
        return array_slice($matches, 1, $matchNum);
    }

    public function checkClient($peerId, $agent, $debug = false)
    {
        //check from high version to low version, if high version allow, stop!
        $allows = AgentAllow::query()
            ->orderBy('peer_id_start', 'desc')
            ->orderBy('agent_start', 'desc')
            ->get();
        $agentAllowPassed = null;
        $versionTooLowStr = '';
        foreach ($allows as $agentAllow) {
            $agentAllowId = $agentAllow->id;
            $isPeerIdAllowed = $isAgentAllowed = $isPeerIdTooLow = $isAgentTooLow = false;
            //check peer_id
            if ($agentAllow->peer_id_pattern == '') {
                $isPeerIdAllowed = true;
            } else {
                $pattern = $agentAllow->peer_id_pattern;
                $start = $agentAllow->peer_id_start;
                $matchType = $agentAllow->peer_id_matchtype;
                $matchNum = $agentAllow->peer_id_match_num;
                try {
                    $peerIdResult = $this->isAllowed($pattern, $start, $matchNum, $matchType, $peerId, $debug);
                    if ($debug) {
                        do_log(
                            "agentAllowId: $agentAllowId, peerIdResult: $peerIdResult, with parameters: "
                            . nexus_json_encode(compact('pattern', 'start', 'matchNum', 'matchType', 'peerId'))
                        );
                    }
                } catch (\Exception $exception) {
                    do_log("agent allow: {$agentAllow->id} check peer_id error: " . $exception->getMessage(), 'error');
                    throw new NexusException("regular expression err for peer_id: " . $start . ", please ask sysop to fix this");
                }
                if ($peerIdResult == 1) {
                    $isPeerIdAllowed = true;
                }
                if ($peerIdResult == 2) {
                    $isPeerIdTooLow = true;
                }
            }

            //check agent
            if ($agentAllow->agent_pattern == '') {
                $isAgentAllowed = true;
            } else {
                $pattern = $agentAllow->agent_pattern;
                $start = $agentAllow->agent_start;
                $matchType = $agentAllow->agent_matchtype;
                $matchNum = $agentAllow->agent_match_num;
                try {
                    $agentResult = $this->isAllowed($pattern, $start, $matchNum, $matchType, $agent, $debug);
                    if ($debug) {
                        do_log(
                            "agentAllowId: $agentAllowId, agentResult: $agentResult, with parameters: "
                            . nexus_json_encode(compact('pattern', 'start', 'matchNum', 'matchType', 'agent'))
                        );
                    }
                } catch (\Exception $exception) {
                    do_log("agent allow: {$agentAllow->id} check agent error: " . $exception->getMessage(), 'error');
                    throw new NexusException("regular expression err for agent: " . $start . ", please ask sysop to fix this");
                }
                if ($agentResult == 1) {
                    $isAgentAllowed = true;
                }
                if ($agentResult == 2) {
                    $isAgentTooLow = true;
                }
            }

            //both OK, passed, client is allowed
            if ($isPeerIdAllowed && $isAgentAllowed) {
                $agentAllowPassed = $agentAllow;
                break;
            }
            if ($isPeerIdTooLow && $isAgentTooLow) {
                $versionTooLowStr = "Your " . $agentAllow->family . " 's version is too low, please update it after " . $agentAllow->start_name;
            }
        }

        if ($versionTooLowStr) {
            throw new NexusException($versionTooLowStr);
        }

        if (!$agentAllowPassed) {
            throw new NexusException("Banned Client, Please goto " . getSchemeAndHttpHost() . "/faq.php#id29 for a list of acceptable clients");
        }

        if ($debug) {
            do_log("agentAllowPassed: " . $agentAllowPassed->toJson());
        }

        // check if exclude
        if ($agentAllowPassed->exception == 'yes') {
            $agentDeny = $this->checkIsDenied($peerId, $agent, $agentAllowPassed->id);
            if ($agentDeny) {
                if ($debug) {
                    do_log("agentDeny: " . $agentDeny->toJson());
                }
                throw new NexusException(sprintf(
                    "[%s-%s]Client: %s is banned due to: %s",
                    $agentAllowPassed->id, $agentDeny->id, $agentDeny->name, $agentDeny->comment
                ));
            }
        }
        if (isHttps() && $agentAllowPassed->allowhttps != 'yes') {
            throw new NexusException(sprintf(
                "[%s]This client does not support https well, Please goto %s/faq.php#id29 for a list of proper clients",
                $agentAllowPassed->id, getSchemeAndHttpHost()
            ));
        }

        return $agentAllowPassed;

    }

    private function checkIsDenied($peerId, $agent, $familyId)
    {
        $agentDenies = AgentDeny::query()->where('family_id', $familyId)->get();
        foreach ($agentDenies as $agentDeny) {
            if ($agentDeny->agent == $agent && preg_match("/^" . $agentDeny->peer_id . "/", $peerId)) {
                return $agentDeny;
            }
        }
    }

    /**
     * check peer_id or agent is allowed
     *
     * 0: not allowed
     * 1: allowed
     * 2: version too low
     *
     * @param $pattern
     * @param $start
     * @param $matchNum
     * @param $matchType
     * @param $value
     * @param bool $debug
     * @return int
     * @throws NexusException
     */
    private function isAllowed($pattern, $start, $matchNum, $matchType, $value, $debug = false): int
    {
        $matchBench = $this->getPatternMatches($pattern, $start, $matchNum);
        if ($debug) {
            do_log("matchBench: " . nexus_json_encode($matchBench));
        }
        if (!preg_match($pattern, $value, $matchTarget)) {
            return 0;
        }
        if ($matchNum <= 0) {
            return 1;
        }
        $matchTarget = array_slice($matchTarget, 1);
        if ($debug) {
            do_log("matchTarget: " . nexus_json_encode($matchTarget));
        }
        for ($i = 0; $i < $matchNum; $i++) {
            if (!isset($matchBench[$i]) || !isset($matchTarget[$i])) {
                break;
            }
            if ($matchType == 'dec') {
                $matchBench[$i] = intval($matchBench[$i]);
                $matchTarget[$i] = intval($matchTarget[$i]);
            } elseif ($matchType == 'hex') {
                $matchBench[$i] = hexdec($matchBench[$i]);
                $matchTarget[$i] = hexdec($matchTarget[$i]);
            } else {
                throw new NexusException(sprintf("Invalid match type: %s", $matchType));
            }
            if ($matchTarget[$i] > $matchBench[$i]) {
                return 1;
            } elseif ($matchTarget[$i] < $matchBench[$i]) {
                return 2;
            }
        }
        return 0;

    }

}
