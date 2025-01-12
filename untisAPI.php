<?php

class UntisAPI {
    private $username;
    private $secret;
    private $school;
    private $url;

    public function __construct($username, $secret, $school) {
        $this->username = $username;
        $this->secret = $secret;
        $this->school = $school;
        $this->url = "https://arche.webuntis.com/WebUntis/jsonrpc_intern.do?a=0&m=getTimetable2017&s=arche.webuntis.com&school=${school}&v=i3.45.1";
    }

    private function base32ToBase64($base32) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';

        foreach (str_split($base32) as $char) {
            $val = strpos($alphabet, strtoupper($char));
            if ($val === false) {
                throw new Exception('Invalid Base32 character');
            }
            $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }

        $base64 = '';
        $base64Alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        for ($i = 0; $i < strlen($bits); $i += 6) {
            $chunk = substr($bits, $i, 6);
            $base64 .= $base64Alphabet[bindec(str_pad($chunk, 6, '0', STR_PAD_RIGHT))];
        }

        return str_pad($base64, ceil(strlen($base64) / 4) * 4, '=');
    }

    private function generateTOTP($secret) {
        $epoch = floor(time() / 30);
        $key = base64_decode($this->base32ToBase64($secret));
        $counter = pack('N*', 0) . pack('N*', $epoch);
        $hmac = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hmac[19]) & 0xf;

        $code = (ord($hmac[$offset]) & 0x7f) << 24 |
            (ord($hmac[$offset + 1]) & 0xff) << 16 |
            (ord($hmac[$offset + 2]) & 0xff) << 8 |
            (ord($hmac[$offset + 3]) & 0xff);

        return str_pad($code % 1000000, 6, '0', STR_PAD_LEFT);
    }

    public function fetchMasterData($startTime = null, $endTime = null) {
        $otp = $this->generateTOTP($this->secret);
        $params = [
            'method' => 'getUserData2017',
            'id' => 'UntisMobilePHP',
            'jsonrpc' => '2.0',
            'params' => [[
                'masterDataTimestamp' => 1724834423826,
                'type' => 'STUDENT',
                'startDate' => (isset($startTime)) ? $startTime : date('Ymd'),
                'endDate' => (isset($endTime)) ? $endTime : date('Ymd'),
                'auth' => [
                    'user' => $this->username,
                    'otp' => $otp,
                    'clientTime' => round(microtime(true) * 1000)
                ],
                'deviceOs' => 'IOS',
                'deviceOsVersion' => '18.0'
            ]]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            return json_encode(['error' => curl_error($ch)]);
        }

        curl_close($ch);
        return $response;
    }

    public function fetchTimetable($startTime = null, $endTime = null) {
        $masterData = json_decode($this->fetchMasterData(), true);

        if (isset($masterData['error'])) {
            return json_encode($masterData);
        }

        $otp = $this->generateTOTP($this->secret);
        $params = [
            'method' => 'getTimetable2017',
            'id' => 'UntisMobilePHP',
            'jsonrpc' => '2.0',
            'params' => [[
                'auth' => [
                    'user' => $this->username,
                    'otp' => $otp,
                    'clientTime' => round(microtime(true) * 1000)
                ],
                'id' => $masterData['result']['userData']['elemId'],
                'type' => 'STUDENT',
                'startDate' => (isset($startTime)) ? $startTime : date('Ymd'),
                'endDate' => (isset($endTime)) ? $endTime : date('Ymd'),
            ]]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            return json_encode(['error' => curl_error($ch)]);
        }

        curl_close($ch);

        $response = json_decode($response, true);

        $periods = $response['result']['timetable']['periods'];

        // Sort periods by start date
        usort($periods, function ($a, $b) {
            return strtotime($a['startDateTime']) - strtotime($b['startDateTime']);
        });

        // Merge periods with same elements
        for ($i = 0; $i < count($periods) - 1; $i++) {
            $current = $periods[$i];
            $next = $periods[$i + 1];

            if ($current['endDateTime'] === $next['startDateTime'] && count($current['elements']) === count($next['elements'])) {
                $equal = true;
                for ($j = 0; $j < count($current['elements']); $j++) {
                    if ($current['elements'][$j]['id'] !== $next['elements'][$j]['id']) {
                        $equal = false;
                        break;
                    }
                }

                if ($equal) {
                    $current['endDateTime'] = $next['endDateTime'];
                    array_splice($periods, $i + 1, 1);
                    $i--;
                }
            }
        }

        //löschen der unnötigen Daten
        foreach ($periods as $key => $period) {
            unset($periods[$key]['can']);
            unset($periods[$key]['blockHash']);
            unset($periods[$key]['foreColor']);
            unset($periods[$key]['backColor']);
            unset($periods[$key]['innerForeColor']);
            unset($periods[$key]['innerBackColor']);
        }

        $masterData = $response['result']['masterData'];
        $classes = $masterData['klassen'];
        $teachers = $masterData['teachers'];
        $subjects = $masterData['subjects'];
        $rooms = $masterData['rooms'];

        foreach ($periods as $key => $period) {
            $elements = $period['elements'];
            $periods[$key]['elements'] = [
                'classes' => [],
                'teachers' => [],
                'subjects' => [],
                'rooms' => []
            ];
            foreach ($elements as $element) {
                if ($element['type'] === 'CLASS') {
                    foreach ($classes as $class) {
                        if ($element['id'] === $class['id']) {
                            $periods[$key]['elements']['classes'][] = $class['name'];
                        }
                    }
                } else if ($element['type'] === 'TEACHER') {
                    foreach ($teachers as $teacher) {
                        if ($element['id'] === $teacher['id']) {
                            $periods[$key]['elements']['teachers'][] = $teacher['name'];
                        }
                    }
                } else if ($element['type'] === 'SUBJECT') {
                    foreach ($subjects as $subject) {
                        if ($element['id'] === $subject['id']) {
                            $periods[$key]['elements']['subjects'][] = $subject['name'];
                        }
                    }
                } else if ($element['type'] === 'ROOM') {
                    foreach ($rooms as $room) {
                        if ($element['id'] === $room['id']) {
                            $periods[$key]['elements']['rooms'][] = $room['name'];
                        }
                    }
                }
            }
        }

        $response['result']['timetable']['periods'] = $periods;

        unset($response['result']['masterData']);

        return json_encode($response);
    }

    public function fetchHomeworks($startTime = null, $endTime = null) {
        $timetable = json_decode($this->fetchTimetable($startTime, $endTime), true);

        $homeworks = [];
        foreach ($timetable['result']['timetable']['periods'] as $period) {
            if (isset($period['homeWorks'])) {
                foreach ($period['homeWorks'] as $homework) {
                    if ($homework['endDate'] === substr($period['endDateTime'], 0, 10)) {
                        $homeworks = array_merge($homeworks, $period['homeWorks']);
                    }
                }
            }
        }

        return json_encode($homeworks);
    }

    public function fetchText($startTime = null, $endTime = null) {
        $timetable = json_decode($this->fetchTimetable($startTime, $endTime), true);

        $text = [];
        foreach ($timetable['result']['timetable']['periods'] as $period) {
            if (isset($period['text'])) {
                $text = array_merge($text, $period['text']);
            }
        }

        return json_encode($text);
    }
}

// Usage
header('Content-Type: application/json');

if (isset($_GET['action'])) {
    $action = $_GET['action'];

    $username = 'username';
    $secret = 'secret';
    $school = 'school';

    if (!isset($_POST['username']) || !isset($_POST['secret']) || !isset($_POST['school'])) {
        echo json_encode(['error' => 'Missing username, secret or school parameter in POST request']);
        exit;
    }else{
        $username = $_POST['username'];
        $secret = $_POST['secret'];
        $school = $_POST['school'];
    }

    $startTime = (isset($_POST['startTime'])) ? str_replace('-', '', $_POST['startTime']) : date('Ymd');
    $endTime = (isset($_POST['endTime'])) ? str_replace('-', '', $_POST['endTime']) : date('Ymd');

    $untisAPI = new UntisAPI($username, $secret, $school);

    switch ($action) {
        case 'fetchMasterData':
            echo $untisAPI->fetchMasterData($startTime, $endTime);
            break;
        case 'fetchTimetable':
            echo $untisAPI->fetchTimetable($startTime, $endTime);
            break;
        case 'fetchHomeworks':
            echo $untisAPI->fetchHomeworks($startTime, $endTime);
            break;
        case 'fetchText':
            echo $untisAPI->fetchText($startTime, $endTime);
            break;
        default:
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} else {
    echo json_encode(['error' => 'Missing action']);
}
