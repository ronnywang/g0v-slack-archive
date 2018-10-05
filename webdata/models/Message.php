<?php

class Message extends Pix_Table
{
    public function init()
    {
        $this->_name = 'message';
        $this->_primary = array('channel_id', 'ts');

        $this->_columns['ts'] = array('type' => 'NUMERIC');
        $this->_columns['channel_id'] = array('type' => 'varchar', 'size' => 16);
        $this->_columns['data'] = array('type' => 'json');
    }
}
