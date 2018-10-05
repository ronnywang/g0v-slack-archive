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

        if (strlen($date) and !preg_match('#\d*-\d*-\d*#', $date)) {
            return $this->redirect('/index/channel/' . urlencode($channel_id));
        }

        if ($date) {
            $this->view->current_date = strtotime($date);
        } else {
            $this->view->current_date = strtotime('0:0:0', Message::search(array('channel_id' => $channel_id))->order('ts DESC')->first()->ts);
        }

        if ($m = Message::search(array('channel_id' => $channel_id))->search("ts < " . $this->view->current_date)->order('ts DESC')->first()) { 
            $this->view->previous_date = strtotime('0:0:0', $m->ts);
        } else {
            $this->view->previous_date = null;
        }

        if ($m = Message::search(array('channel_id' => $channel_id))->search("ts > 86400 + " . $this->view->current_date)->order('ts ASC')->first()) { 
            $this->view->next_date = strtotime('0:0:0', $m->ts);
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
        $user_id = $obj->user->id;
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
}

