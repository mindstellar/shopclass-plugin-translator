<?php
echo __('It\'s "quoted" and a \\ backslash', 'tplfix');
echo __("Line one\nLine two\t\"tab\"", 'tplfix');
echo __('Hello', 'tplfix');
echo \__('Fully qualified', 'tplfix');
function __x() { return __(sprintf('%s', 'x'), 'tplfix'); }
