<?php

class Channel extends Pix_Table
{
    public function init()
    {
        $this->_name = 'channel';
        $this->_primary = 'id';

        $this->_columns['id'] = array('type' => 'varchar', 'size' => 16);
        $this->_columns['name'] = array('type' => 'varchar', 'size' => 16);
        $this->_columns['data'] = array('type' => 'json');

        $this->addIndex('name', array('name'));
    }
}
