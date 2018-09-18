<?php

class Message extends Pix_Table
{
    public function init()
    {
        $this->_name = 'message';
        $this->_primary = 'ts';

        $this->_columns['ts'] = array('type' => 'varchar', 'size' => 16);
        $this->_columns['channel'] = array('type' => 'varchar', 'size' => 16);
        $this->_columns['data'] = array('type' => 'json');

        $this->addIndex('channel_ts', array('channel', 'ts'));
    }
}
