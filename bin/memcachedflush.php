<?php
use Stash\Driver\Memcache;
use Stash\Item;
use Stash\Pool;

echo"Flushing memcached servers....";
        
$memINI = eZINI::instance( 'xrowaws.ini' );
        
if($memINI->hasVariable("MemcacheSettings", "Host") AND $memINI->hasVariable("MemcacheSettings", "Port"))
{
    $mem_host = $memINI->variable( "MemcacheSettings", "Host" );
    $mem_port = $memINI->variable( "MemcacheSettings", "Port" );
}

$driver = new Memcache(array('servers' => array($mem_host, $mem_port)));
$pool = new Pool($driver);
$pool->flush();

$db = eZDB::instance();
$db->query("USE silentcaldfscluster");
$db->query("DELETE FROM ezdfsfile WHERE name_trunk like '%/cache/%'");
