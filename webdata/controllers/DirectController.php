<?php

class DirectController extends Pix_Controller
{
    public function init()
    {
        if (!Pix_Session::get('user_name')) {
            return $this->alert('need login', '/');
        }

        $user = new StdClass;
        $user->name = Pix_Session::get('user_name');
        $user->id = Pix_Session::get('user_id');
        $this->view->user = $user;
    }

    public function indexAction()
    {
    }

    public function channelAction()
    {
        list(,/*index*/, /*channel*/, $channel_id, $date) = explode('/', $this->getURI());
        if (!$this->view->channel = DirectChannel::find($channel_id)) {
            return $this->alert('channel not found', '/');;
        }
        if (!DirectChannelUser::search(array('channel_id' => $channel_id, 'user_id' => $this->view->user->id))->count()) {
            return $this->alert('channel not found', '/');;
        }

        if (strlen($date) and !preg_match('#\d*-\d*#', $date)) {
            return $this->redirect('/direct/channel/' . urlencode($channel_id));
        }

        if ($date) {
            $current_date = mktime(0, 0, 0, explode('-', $date)[1], 1, explode('-', $date)[0]);
        } else {
            $ts = DirectMessage::search(array('channel_id' => $channel_id))->order('ts DESC')->first()->ts;
            $current_date = mktime(0, 0, 0, date('m', $ts), 1, date('Y', $ts));
        }
        $this->view->current_date = $current_date;

        if ($m = DirectMessage::search(array('channel_id' => $channel_id))->search("ts < " . $this->view->current_date)->order('ts DESC')->first()) { 
            $this->view->previous_date = mktime(0, 0, 0, date('m', $m->ts), 1, date('Y', $m->ts));
        } else {
            $this->view->previous_date = null;
        }

        if ($m = DirectMessage::search(array('channel_id' => $channel_id))->search("ts > " . strtotime('next month', $this->view->current_date))->order('ts ASC')->first()) { 
            $this->view->next_date = mktime(0, 0, 0, date('m', $m->ts), 1, date('Y', $m->ts));
        } else {
            $this->view->next_date = null;
        }
    }

    public function reloadlinkAction()
    {
        $duser = DirectUser::find($this->view->user->id);
        $duser->fetchData();
        return $this->redirect('/direct/');
    }

    public function updateAction()
    {
        $channel_id = strval($_GET['id']);
        if (!$channel = DirectChannel::find($channel_id)) {
            return $this->alert('channel not found', '/direct');;
        }
        if (!DirectChannelUser::search(array('channel_id' => $channel_id, 'user_id' => $this->view->user->id))->count()) {
            return $this->alert('channel not found', '/direct');;
        }
        $channel->fetchMessages();
        if ($_GET['autonext']) {
            return $this->redirect('/direct?autonext=1');
        } else {
            return $this->redirect('/direct');
        }
    }
}

