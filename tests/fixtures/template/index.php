<?php
/*
Plugin Name: Template fixture
*/
echo __('Hello', 'tplfix');
_e('Other domain', 'someone-else');
echo __('Core text');
echo __($dynamic, 'tplfix');
echo __('Domain is a variable', $domain);
printf(_n('%d item', '%d items', $count, 'tplfix'), $count);
echo $obj->__('Method call', 'tplfix');
echo Foo::__('Static call', 'tplfix');
echo _x('Post', 'verb', 'tplfix');
