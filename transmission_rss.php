<?php
/**
 * Transmission simple RPC/0.1
 *
 * @author  fengqi <lyf362345@gmail.com>
 * @link    https://github.com/fengqi/transmission-rss
 */
class Transmission {
    private $server;
    private $user;
    private $password;
    private $session_id;
    private $torrent_list;
    /**
     *
     * @param $server
     * @param string $port
     * @param string $rpcPath
     * @param string $user
     * @param string $password
     *
     * @return \Transmission
     */
    public function __construct($server, $port = '9091', $rpcPath = '/transmission/rpc', $user = '', $password = '')
    {
        $this->server = $server.':'.$port.$rpcPath;
        $this->user = $user;
        $this->password = $password;
        $this->session_id = $this->getSessionId();
        $torrent_file = fopen('./torrent_list.json', 'r') or die('file open error!!');
        $torrent_json = '';
        $torrent_json = fread($torrent_file, filesize('./torrent_list.json'));
        if ($torrent_json == '') {
            $this->torrent_list = array();
        } else {
            $this->torrent_list = json_decode($torrent_json, true);
        }
        fclose($torrent_file);
    }
    /**
     *
     * @param $url
     * @param bool $isEncode
     * @param array $options
     * @return mixed
     */
    public function add($url, $isEncode = false, $options = array())
    {
        return $this->request('torrent-add', array_merge($options, array(
            $isEncode ? 'metainfo' : 'filename' => $url,
        )));
    }
    /**
     *
     * @return mixed
     */
    public function status()
    {
        return $this->request("session-stats");
    }
    /**
     *
     * @return string
     */
    public function getSessionId()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->server);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->user.':'.$this->password);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $content = curl_exec($ch);
        curl_close($ch);
        preg_match("/<code>(X-Transmission-Session-Id: .*)<\/code>/", $content, $content);
        $this->session_id = $content[1];
        return $this->session_id;
    }
    /**
     *
     * @param $method 请求类型/方法, 详见 $this->allowMethods
     * @param array $arguments 附加参数, 可选
     * @return mixed
     */
    private function request($method, $arguments = array())
    {
        $data = array(
            'method' => $method,
            'arguments' => $arguments
        );
        $header = array(
            'Content-Type: application/json',
            'Authorization: Basic '.base64_encode(sprintf("%s:%s", $this->user, $this->password)),
            $this->session_id
        );
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->server);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->user.':'.$this->password);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $content = curl_exec($ch);
        curl_close($ch);
        if (!$content) $content = json_encode(array('result' => 'failed'));
        return $content;
    }
    /**
     * 获取 rss 的种子列表
     *
     * @param $rss
     * @return array
     */
    function getRssItems($rss)
    {
        $items = array();
        foreach ($rss as $keyword=>$link) {
            $content = file_get_contents($link);
            $content = preg_replace('#&(?=[a-z_0-9]+=)#', '&amp;', $content); 
            $xml = new DOMDocument();
            $xml->loadXML($content);
            // $xml->loadHTML($content);
            // $xml->load($link);
            $elements = $xml->getElementsByTagName('item');
            foreach ($elements as $item) {
                $link = $item->getElementsByTagName('enclosure')->item(0) != null ?
                        $item->getElementsByTagName('enclosure')->item(0)->getAttribute('url') :
                        $item->getElementsByTagName('link')->item(0)->nodeValue;
                $items[] = array(
                    'title' => $item->getElementsByTagName('title')->item(0)->nodeValue,
                    'link' => $link,
                    'keyword' => $keyword,
                );
            }
        }
        return $items;
    }
    function checkOlderItem($keyword, $title) {
        $title = str_replace("+", " ", $title);
        $title_arr = explode('.', $title);
        $main_title = $title_arr[0];
        $episode = $title_arr[1];
        $is_exist = false;
        for ($i = 0; $i < count($this->torrent_list); $i++) {
            $torrent_info = $this->torrent_list[$i];
            if ($torrent_info['title'] == $keyword) {
                if (strcmp($episode, $torrent_info['episode']) > 0) {
                    // $this->torrent_list[$i]['episode'] = $episode;
                    return true;
                }
                $is_exist = true;
            }
        }
        if (!$is_exist) {
            array_push($this->torrent_list, array('title' => $keyword, 'episode' => $episode));
        }
        return false;
    }
    function writeTorrentList() {
        $torrent_file = fopen('./torrent_list.json', 'w+') or die('file open error!!');
        fwrite($torrent_file, json_encode($this->torrent_list));
        fclose($torrent_file);
    }
    function episodeUpdate($keyword, $title) {
        $title = str_replace("+", " ", $title);
        $title_arr = explode('.', $title);
        $main_title = $title_arr[0];
        $episode = $title_arr[1];
        for ($i = 0; $i < count($this->torrent_list); $i++) {
            $torrent_info = $this->torrent_list[$i];
            if ($torrent_info['title'] == $keyword) {
                if (strcmp($episode, $torrent_info['episode']) > 0) {
                    $this->torrent_list[$i]['episode'] = $episode;
                }
            }
        }
    }
}
$rss = array(
        '무한도전'=>'https://torrentkim3.net/bbs/rss.php?k=무한도전+NEXT+720&b=torrent_variety',
        '썰전'=>'https://torrentkim3.net/bbs/rss.php?k=썰전+NEXT+720&b=torrent',
        '아는형님'=>'https://torrentkim3.net/bbs/rss.php?k=아는형님+NEXT+720&b=torrent_variety',
        '1박2일'=>'https://torrentkim3.net/bbs/rss.php?k=1박2일+NEXT+720&b=torrent_variety',
        'M16'=>'https://torrentkim5.net/bbs/rss.php?k=M16+NEXT+720&b=torrent_variety',
        '차이나는'=>'https://torrentkim5.net/bbs/rss.php?k=차이나는+NEXT+720&b=torrent'
);
$server = 'http://127.0.0.1';
$port = 9091;
$rpcPath = '/transmission/rpc';
$user = 'transmisstion';
$password = 'password';
$trans = new Transmission($server, $port, $rpcPath, $user, $password);
$torrents = $trans->getRssItems($rss);
foreach ($torrents as $torrent) {
    if ($trans->checkOlderItem($torrent['keyword'], $torrent['title'])) {
        $response = json_decode($trans->add($torrent['link']));
        if ($response->result == 'success') {
            printf("%s: success add torrent: %s\n", date('Y-m-d H:i:s'), $torrent['title']);
            $trans->episodeUpdate($torrent['keyword'], $torrent['title']);
        }
    }
}
$trans->writeTorrentList();
?>