<?php

class IndexController extends Pix_Controller
{
    public function init()
    {
        if (Pix_Session::get('user_name')) {
            $user = new StdClass;
            $user->name = Pix_Session::get('user_name');
            $user->id = Pix_Session::get('user_id');
            $this->view->user = $user;
        }
    }

    public function indexAction()
    {
        if ($private_channel = Pix_Session::get('private_channel')) {
            $this->view->private_channel = json_decode($private_channel);
        }
    }

    public function rssAction()
    {
        list(,/*index*/, /*rss*/, $channel_id) = explode('/', $this->getURI());
        if (!$channel = Channel::find($channel_id)) {
            return $this->alert('channel not found', '/');;
        }

        $data = json_decode($channel->data);
        if ($data->is_private) {
            return $this->alert('channel not found', '/');
        }
        $messages = Message::search(array('channel_id' => $channel->id))->order('ts DESC')->limit(30);

        $rss = new SimpleXMLElement('<rss version="2.0"></rss>');
        $obj = $rss->addChild('channel');
        $obj->addChild('title', $channel->name);
        $obj->addChild('link', 'https://' . $_SERVER['HTTP_HOST'] . '/index/channel/' . $channel->id);
        $obj->addChild('description', $channel->name);
        $obj->addChild('language', 'zh-tw');
        foreach ($messages as $message) {
            $data = json_decode($message->data);
            if ($data->subtype ?? false) {
                continue;
            }
            $item = $obj->addChild('item');
            $user = User::find($data->user);
            $item->addChild('title', $user->name . '@' . date('Y-m-d H:i:s', $message->ts));
            $item->addChild('link', 'https://' . $_SERVER['HTTP_HOST'] . '/index/channel/' . $channel->id . '/' . date('Y-m', $message->ts) . '#ts-' . $message->ts);
            $item->addChild('description', Message::getHTML($data));
            $item->addChild('pubDate', date('r', $message->ts));
        }
        header('Content-Type: text/xml');
        //header('Content-Type: application/rss+xml');
        echo $rss->asXML();
        return $this->noview();
    }

    public function channelAction()
    {
        list(,/*index*/, /*channel*/, $channel_id, $date) = explode('/', $this->getURI());
        if (!$this->view->channel = Channel::find($channel_id)) {
            return $this->alert('channel not found', '/');;
        }

        $data = json_decode($this->view->channel->data);
        if ($data->is_private) {
            if (!$this->view->user->id) {
                return $this->alert('channel not found', '/');
            }

            if (!ChannelUser::search(array('channel_id' => $channel_id, 'user_id' => $this->view->user->id))->count()) {
                return $this->alert('channel not found', '/');
            }
        }

        if (strlen($date) and !preg_match('#\d*-\d*#', $date)) {
            return $this->redirect('/index/channel/' . urlencode($channel_id));
        }

        if ($date) {
            $current_date = mktime(0, 0, 0, explode('-', $date)[1], 1, explode('-', $date)[0]);
        } else {
            $ts = Message::search(array('channel_id' => $channel_id))->order('ts DESC')->first()->ts;
            $current_date = mktime(0, 0, 0, date('m', $ts), 1, date('Y', $ts));
        }
        $this->view->current_date = $current_date;

        if ($m = Message::search(array('channel_id' => $channel_id))->search("ts < " . $this->view->current_date)->order('ts DESC')->first()) { 
            $this->view->previous_date = mktime(0, 0, 0, date('m', $m->ts), 1, date('Y', $m->ts));
        } else {
            $this->view->previous_date = null;
        }

        if ($m = Message::search(array('channel_id' => $channel_id))->search("ts > " . strtotime('next month', $this->view->current_date))->order('ts ASC')->first()) { 
            $this->view->next_date = mktime(0, 0, 0, date('m', $m->ts), 1, date('Y', $m->ts));
        } else {
            $this->view->next_date = null;
        }
    }

    public function logincallbackAction()
    {
        $client_id = getenv('SLACK_CLIENT_ID');
        $client_secret = getenv('SLACK_CLIENT_SECRET');
        $redirect_uri = 'https://' . getenv('SLACK_CALLBACK_HOST') . '/index/logincallback';
        if (!$code = $_GET['code']) {
            return $this->alert("Error", '/index/login');
        }

        $url = "https://slack.com/api/oauth.access";
        $url .= "?client_id=" . urlencode($client_id);
        $url .= "&client_secret=" . urlencode($client_secret);
        $url .= "&code=" . urlencode($code);
        $url .= "&redirect_uri=" . urlencode($redirect_uri);
        $obj = json_decode(file_get_contents($url));
        if (!$obj->ok) {
            return $this->alert($obj->error, '/index/login');
        }
        $access_token = $obj->access_token;
        $user_id = $obj->user_id;
        $url = sprintf('https://slack.com/api/users.identity?token=%s', urlencode($access_token));
        $obj = json_decode(file_get_contents($url));
        if (!$obj->ok) {
            return $this->alert($obj->error, '/index/login');
        }

        if ($_GET['state'] == 'backup') {
            $url = sprintf('https://slack.com/api/conversations.list?types=private_channel&token=%s', urlencode($access_token));
            $private_obj = json_decode(file_get_contents($url));
            foreach ($private_obj->channels as $channel) {
                try {
                    Channel::insert(array(
                        'id' => $channel->id,
                        'name' => $channel->name,
                        'data' => json_encode($channel),
                        'last_fetched_at' => 0,
                        'private_config' => '{}',
                    ));
                } catch (Pix_Table_DuplicateException $e) {
                }
            }

            Pix_Session::set('private_channel', json_encode(array_map(function($channel){
                return $channel->id;
            }, $private_obj->channels)));
        } else if ($_GET['state'] == 'im') {
            if (!DirectUser::find($user_id)) {
                DirectUser::insert(array(
                    'id' => $user_id,
                    'created_at' => time(),
                    'data' => '{}',
                ));
            }
            $du = DirectUser::find($user_id);
            $data = json_decode($du->data);
            $data->access_token = $access_token;
            $du->update(array('data' => json_encode($data)));
        } else {
            Pix_Session::set('private_channel', json_encode(array_values(ChannelUser::search(array('user_id' => $user_id))->toArray('channel_id'))));
        }

        Pix_Session::set('user_id', $user_id);
        Pix_Session::set('user_name', $obj->user->name);
        Pix_Session::set('access_token', $access_token);

        return $this->redirect('/');
    }

    public function logoutAction()
    {
        Pix_Session::set('user_id', '');
        Pix_Session::set('user_name', '');
        Pix_Session::set('access_token', '');
        Pix_Session::set('private_channel', '');
        return $this->redirect('/');
    }

    public function loginAction()
    {
        $client_id = getenv('SLACK_CLIENT_ID');
        $redirect_uri = 'https://' . getenv('SLACK_CALLBACK_HOST') . '/index/logincallback';
        if ($_GET['type'] == 'read') {
            $url = sprintf("https://slack.com/oauth/authorize?client_id=%s&scope=%s&redirect_uri=%s&state=%s&team=%s",
                urlencode($client_id), // client_id
                'identity.basic', // scope
                urlencode($redirect_uri), // redirect_uri
                "read", // state
                "" // team
            );
            return $this->redirect($url);
        } elseif ($_GET['type'] == 'backup') {
            $url = sprintf("https://slack.com/oauth/authorize?client_id=%s&scope=%s&redirect_uri=%s&state=%s&team=%s",
                urlencode($client_id), // client_id
                urlencode("groups:history,groups:read,channels:read"), // scope
                urlencode($redirect_uri), // redirect_uri
                "backup", // state
                "" // team
            );
            return $this->redirect($url);
        } elseif ($_GET['type'] == 'im') {
            $url = sprintf("https://slack.com/oauth/authorize?client_id=%s&scope=%s&redirect_uri=%s&state=%s&team=%s",
                urlencode($client_id), // client_id
                urlencode("im:history,im:read"), // scope
                urlencode($redirect_uri), // redirect_uri
                "im", // state
                "" // team
            );
            return $this->redirect($url);
        } else {
            return;
        }
    }

    public function addprivatechannelAction()
    {
        $channel = $_GET['channel'];
        $access_token = Pix_Session::get('access_token');
        if (!array_key_exists('channel', $_GET) or !is_scalar($_GET['channel']) or !$channel = Channel::find($_GET['channel'])) {
            return $this->alert('no channel', '/');
        }

        try {
            $channel->updateChannel($access_token);
        } catch (MyException $e) {
            return $this->alert($e->getMessage(), '/');
        }

        $data = json_decode($channel->data);
        if (!$data->is_private) {
            return $this->alert("not a private channel", '/');
        }

        $channel->update(array(
            'private_config' => json_encode(array(
                'access_token' => $access_token,
                'config_at' => time(),
                'token_owner' => Pix_Session::get('user_id'),
            )),
        ));

        $channel->fetchMessages();

        return $this->redirect('/');
    }

    public function searchAction()
    {
        $q = $_GET['q'];
        if (trim($q) == '') {
            return $this->redirect('/');
        }
        $this->view->q = $q;
    }

    public function callbackAction()
    {
        $request_body = file_get_contents('php://input');
        $slack_signing_secret = getenv('SLACK_SIGNING_SECRET');
        $timestamp = $_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'];
        if (abs($timestamp - time()) > 60 * 5) {
            return $this->json(array('error' => true, 'message' => 'timeout'));
        }
        $sig_basestring = 'v0:' . $timestamp . ':' . $request_body;
        $my_signature = 'v0=' . hash_hmac('sha256', $sig_basestring, $slack_signing_secret);
        if ($my_signature != $_SERVER['HTTP_X_SLACK_SIGNATURE']) {
            return $this->json(array('error' => true, 'message' => 'signature error'));
        }

        $data = json_decode($request_body);
        if (!$data or !property_exists($data, 'type')) {
            return $this->json(0);
        }

        if ($data->type == 'url_verification') {
            return $this->json(array('challenge' => $data->challenge));
        }

        if ($data->type == 'event_callback') {
            $message = $data->event;
            if ($message->type == 'message' and !property_exists($message, 'subtype')) {
                Message::insert(array(
                    'ts' => floatval($message->ts),
                    'channel_id' => $message->channel,
                    'data' => json_encode($message),
                ));
            }
        }

        if (getenv('EVENT_FORWARD_URL')) {
            $curl = curl_init(getenv('EVENT_FORWARD_URL'));
            curl_setopt($curl, CURLOPT_POSTFIEDS, $data);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                "Content-Type: text/json",
            ]);
            curl_exec($curl);
            curl_close($curl);
        }

        return $this->json(0);
    }

    public function getmessageAction()
    {
        $channel_id = $_GET['channel'];
        if (!$channel_id) {
            return $this->json(0);
        }
        if (!$this->view->channel = Channel::find($channel_id)) {
            return $this->json(0);
        }
        $data = json_decode($this->view->channel->data);
        if ($data->is_private) {
            if (!$this->view->user->id) {
                return $this->json(0);
            }

            if (!ChannelUser::search(array('channel_id' => $channel_id, 'user_id' => $this->view->user->id))->count()) {
                return $this->json(0);
            }
        }

        if (array_key_exists('count', $_GET)) {
            $c = min(100, intval($_GET['count']));
        } else {
            $c = 3;
        }
        $ret = new StdClass;
        $ret->messages = array();
        $after = $before = null;
        if (array_key_exists('after', $_GET)) {
            $after = floatval($_GET['after']);
            $messages = Message::search(array('channel_id' => $channel_id))->order('ts DESC')->search('ts > ' . $after)->limit($c);
        } else if (array_key_exists('before', $_GET)) {
            $before = floatval($_GET['before']);
            $messages = Message::search(array('channel_id' => $channel_id))->order('ts DESC')->search('ts < ' . $before)->limit($c);
        } else {
            $messages = Message::search(array('channel_id' => $channel_id))->order('ts DESC')->limit($c);
        }
        foreach ($messages as $message) {
            $data = json_decode($message->data);
			if (property_exists($data, 'subtype')) {
				continue;
			}
            $data->user = json_decode(User::find($data->user)->data);
            $data->html_content = Message::getHTML($data);
            $ret->messages[] = $data;
            if (is_null($after)) {
                $after = $message->ts;
            } else {
                $after = max($after, $message->ts);
            }
            if (is_null($before)) {
                $before = $message->ts;
            } else {
                $before = min($before, $message->ts);
            }
        }
        $ret->next_url = 'https://' . $_SERVER['HTTP_HOST'] . '/index/getmessage?channel=' . urlencode($channel_id) . '&after=' . $after . '&count=' . $c;
        $ret->previous_url = 'https://' . $_SERVER['HTTP_HOST'] . '/index/getmessage?channel=' . urlencode($channel_id) . '&before=' . $before . '&count=' . $c;
        header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
        header('Access-Control-Allow-Methods: GET');
        return $this->json($ret);
    }

    public function countAction()
    {
        return $this->json(array(
            'users' => User::search(1)->count(),
            'channels' => Channel::search(1)->count(),
            'messages' => Message::search(1)->count(),
        ));
    }

    public function fileAction()
    {
        $url = $_GET['url'];
        if (strpos($url, 'https://files.slack.com/') !== 0) {
            return $this->redirect('/');
        }

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . getenv('SLACK_ACCESS_TOKEN'),
        ));
        curl_exec($curl);
        return $this->noview();
    }
}

