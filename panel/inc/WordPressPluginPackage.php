<?php
/** Small, dependency-free ZIP of the reviewed plugin files (STORE format). */
class WordPressPluginPackage {
    public static function build(){
        $root=dirname(__DIR__,2).'/integrations/wordpress/maarksho-crm-sync/';$body='';$directory='';$count=0;
        foreach(['maarksho-crm-sync.php','settings-view.php','assets/admin.css','assets/admin.js'] as $relative){
            $data=file_get_contents($root.$relative);if($data===false)throw new RuntimeException('فایل افزونه در دسترس نیست.');
            $name='maarksho-crm-sync/'.$relative;$size=strlen($data);$crc=crc32($data);$offset=strlen($body);$length=strlen($name);
            $body.=pack('VvvvvvVVVvv',0x04034b50,20,0,0,0,33,$crc,$size,$size,$length,0).$name.$data;
            $directory.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,0,0,33,$crc,$size,$size,$length,0,0,0,0,0,$offset).$name;$count++;
        }
        return $body.$directory.pack('VvvvvVVv',0x06054b50,0,0,$count,$count,strlen($directory),strlen($body),0);
    }
}
