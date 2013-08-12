<?php

class xrowS3FileHandlerMySQLiBackend extends eZDFSFileHandlerMySQLiBackend implements eZClusterEventNotifier
{

    /**
     * Connects to the database.
     *
     * @return void
     * @throw eZClusterHandlerDBNoConnectionException
     * @throw eZClusterHandlerDBNoDatabaseException
     */
    public function _connect()
    {
        $siteINI = eZINI::instance( 'site.ini' );
        // DB Connection setup
        // This part is not actually required since _connect will only be called
        // once, but it is useful to run the unit tests. So be it.
        // @todo refactor this using eZINI::setVariable in unit tests
        if ( self::$dbparams === null )
        {
            $fileINI = eZINI::instance( 'file.ini' );

            self::$dbparams = array();
            self::$dbparams['host']       = $fileINI->variable( 'eZDFSClusteringSettings', 'DBHost' );
            $dbPort = $fileINI->variable( 'eZDFSClusteringSettings', 'DBPort' );
            self::$dbparams['port']       = $dbPort !== '' ? $dbPort : null;
            self::$dbparams['socket']     = $fileINI->variable( 'eZDFSClusteringSettings', 'DBSocket' );
            self::$dbparams['dbname']     = $fileINI->variable( 'eZDFSClusteringSettings', 'DBName' );
            self::$dbparams['user']       = $fileINI->variable( 'eZDFSClusteringSettings', 'DBUser' );
            self::$dbparams['pass']       = $fileINI->variable( 'eZDFSClusteringSettings', 'DBPassword' );

            self::$dbparams['max_connect_tries'] = $fileINI->variable( 'eZDFSClusteringSettings', 'DBConnectRetries' );
            self::$dbparams['max_execute_tries'] = $fileINI->variable( 'eZDFSClusteringSettings', 'DBExecuteRetries' );

            self::$dbparams['sql_output'] = $siteINI->variable( "DatabaseSettings", "SQLOutput" ) == "enabled";

            self::$dbparams['cache_generation_timeout'] = $siteINI->variable( "ContentSettings", "CacheGenerationTimeout" );
        }

        $serverString = self::$dbparams['host'];
        if ( self::$dbparams['socket'] )
            $serverString .= ':' . self::$dbparams['socket'];
        elseif ( self::$dbparams['port'] )
            $serverString .= ':' . self::$dbparams['port'];

        $maxTries = self::$dbparams['max_connect_tries'];
        $tries = 0;
        eZDebug::accumulatorStart( 'mysql_cluster_connect', 'MySQL Cluster', 'Cluster database connection' );
        while ( $tries < $maxTries )
        {
            if ( $this->db = mysqli_connect( self::$dbparams['host'], self::$dbparams['user'], self::$dbparams['pass'], self::$dbparams['dbname'], self::$dbparams['port'] ) )
                break;
            ++$tries;
        }
        eZDebug::accumulatorStop( 'mysql_cluster_connect' );
        if ( !$this->db )
            throw new eZClusterHandlerDBNoConnectionException( $serverString, self::$dbparams['user'], self::$dbparams['pass'] );

        /*if ( !mysql_select_db( self::$dbparams['dbname'], $this->db ) )
            throw new eZClusterHandlerDBNoDatabaseException( self::$dbparams['dbname'] );*/

        // DFS setup
        if ( $this->dfsbackend === null )
        {
            $this->dfsbackend = new xrowS3FileHandlerDFSBackend();
        }

        $charset = trim( $siteINI->variable( 'DatabaseSettings', 'Charset' ) );
        if ( $charset === '' )
        {
            $charset = eZTextCodec::internalCharset();
        }

        if ( $charset )
        {
            if ( !mysqli_set_charset( $this->db, eZMySQLCharset::mapTo( $charset ) ) )
            {
                $this->_fail( "Failed to set Database charset to $charset." );
            }
        }
    }
}