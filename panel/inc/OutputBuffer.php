<?php
/** Use one compression owner: hosting-level zlib or the application's gzip handler. */
class OutputBuffer {
    public static function start() {
        $setting=strtolower(trim((string)ini_get('zlib.output_compression')));
        $compressed=!in_array($setting,['','0','off','false','no'],true);
        foreach(ob_list_handlers() as $handler) {
            if(in_array(strtolower($handler),['ob_gzhandler','zlib output compression'],true))$compressed=true;
        }
        if(!$compressed&&function_exists('ob_gzhandler')) {
            if(ob_start('ob_gzhandler'))return true;
        }
        return ob_start();
    }
}
