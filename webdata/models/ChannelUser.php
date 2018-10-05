<?php

class ChannelUser extends Pix_Table
{
    public function init()
    {
        $this->_name = 'channel_user';
        $this->_primary = array('channel_id', 'user_id');

        $this->_columns['channel_id'] = array('type' => 'varchar', 'size' => 16);
        $this->_columns['user_id'] = array('type' => 'varchar', 'size' => 16);

        $this->addIndex('user_id', array('user_id'));
    }
}
