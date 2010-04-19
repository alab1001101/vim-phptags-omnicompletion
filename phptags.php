<?php

class PHPTAGS
{
	private $_file;
	private $_mode;
	private $_force;
	private $_debug;
	private $_includepath;

	private $_appBoot = "../public/index.php";
	private $_sqlFile = "phptags.sqlite";

	private $_sqlInit = "-- Init
--
CREATE TABLE IF NOT EXISTS phptags (
	tagname TEXT,
	tagfile TEXT,
	tagadress TEXT,
	tagclass TEXT,
	kind TEXT,
	file TEXT,
	class TEXT,
	visibility TEXT,
	global BOOL DEFAULT NULL,
	static BOOL DEFAULT NULL,
	final BOOL DEFAULT NULL,
	abstract BOOL DEFAULT NULL,
	params TEXT,
	returns TEXT,
	UNIQUE(
		tagname, tagclass, tagfile, kind, class, file
	) ON CONFLICT REPLACE
);
CREATE VIEW IF NOT EXISTS 'PHP' AS SELECT DISTINCT * FROM phptags WHERE tagfile='PHP';
CREATE VIEW IF NOT EXISTS '!VIEW!' AS
	SELECT * FROM phptags WHERE tagfile='!VIEW!' OR global=1;
CREATE VIEW IF NOT EXISTS '!TABLE!' AS
	SELECT * FROM phptags WHERE class='!TABLE!';
--
-- /Init";

	private $_sqlStmt = "-- Statement
--
INSERT !IGNORE! INTO phptags VALUES (
	'!tagname!',
	'!tagfile!',
	'!tagadress!',
	'!tagclass!',
	'!kind!',
	'!file!',
	'!class!',
	'!visibility!',
	!global!,
	!static!,
	!final!,
	!abstract!,
	'!params!',
	'!returns!'
);
--
-- /Statement";

	private $_sqlClass = '';
	private $_sqlView = '';
	private $_sqlIgnore = 'OR IGNORE';

	private $_sources = array( 'PHP' => array() );
	private $_classes;
	private $_functions;
	private $_tags = array();

	private $_tagname;
	private $_tagfile;
	private $_tagadress;
	private $_tagclass;
	private $_kind;

	private $_class;
	private $_visibility;
	private $_global;
	private $_static;
	private $_final;
	private $_abstract;
	private $_params;
	private $_returns;

	private function _addTag()
	{
		$this->_tags[] = str_replace(
			array(
				'!IGNORE!',
				'!tagname!',
				'!tagfile!',
				'!tagadress!',
				'!tagclass!',
				'!kind!',
				'!file!',
				'!class!',
				'!visibility!',
				'!global!',
				'!static!',
				'!final!',
				'!abstract!',
				'!params!',
				'!returns!'
			),
			array(
				$this->_sqlIgnore,
				$this->_tagname,
				$this->_tagfile,
				$this->_tagadress,
				$this->_tagclass,
				$this->_kind,
				$this->_file,
				$this->_class,
				$this->_visibility,
				( $this->_global ? 1:'NULL' ),
				( $this->_static ? 1:'NULL' ),
				( $this->_final ? 1:'NULL' ),
				( $this->_abstract ? 1:'NULL' ),
				$this->_params,
				$this->_returns
			),
			$this->_sqlStmt
		);
	}

	private function _sqliteInsert()
	{
		$this->_insertSplit[] = $this->_tags;
		
		while (current($this->_insertSplit)) {
			$sql = escapeshellarg("BEGIN TRANSACTION;"
                                  . str_replace(array('!TABLE!',
                                                      '!VIEW!'),
                                                array($this->_sqlClass,
                                                      $this->_sqlView),
                                                $this->_sqlInit)
                                  . join((array)current($this->_insertSplit),
                                         "\n")
                                  . "\nCOMMIT;");

			passthru('sqlite3 "' . $this->_sqlFile . '" ' . $sql, $e);

			if ($e > 0) {
				$this->_log("Sqlite Error: $e");
				// For error codes see http://www.sqlite.org/c_interface.html
				// $e == 127, is a shell error, and it seems that arguments 
				// are too long.
				if ($e > 23) {
					$c = count($this->_insertSplit);
					$this->_insertSplit = array_chunk($this->_tags, ++$c);
					$this->_log("Chunked SQL Insert String into $c peaces.");
					continue;
				}
			}
			next( $this->_insertSplit );
		}
		//$this->_log( $this->_class . '::' . __METHOD__ . '::' . __LINE__, $e );
	}

    private function _entryExist()
    {
        if ($this->_force) {
            return false;
        }
        $e = exec("sqlite3 -line '$this->_sqlFile' '.tables $this->_file'");
        return (bool)$e;
    }

	public function __construct($p)
	{
		$this->_classes   = get_declared_classes();
		$this->_functions = get_defined_functions();
		$this->_setParams($p);
	}

	public function run()
	{
        if ($this->_entryExist()) {
            return;
        }
		$this->{"_setup" . strtoupper( $this->_mode )}();
		$this->_tagClasses();
		$this->_sqliteInsert();
	}

	public static function autoload($c)
	{
		try {
			include str_replace( '_','/', $c . '.php' );
		}
		catch( Exception $e )
		{
			echo $e->getMessage() . "\n\n";
		}
		
		/*
		if( !class_exists( $c, false ) &&
		    !interface_exists( $c, false ) &&
			!in_array( $c, get_declared_classes() ) &&
			!in_array( $c, get_declared_interfaces() )
		)
		echo __METHOD__ . " Catched Fatal error: $c does not exist.\n\n";
		 */
	}

	private function _setupPHP()
	{
		$this->_filter('internal');
	}

	private function _setupLIB()
	{
		spl_autoload_register( 'PHPTAGS::autoload' );
		$this->_sqlDrop = '';
		set_include_path(
			$this->_includepath .
			PATH_SEPARATOR .
			get_include_path()
		);
		$this->_tagfile = $this->_file;
		$this->_sqlView = $this->_tagfile;
		$this->_addSource();
		$this->_grepClassName();
		if( $this->_class )
		{
			//include_once( $this->_tagfile );
			$this->_sqlClass = $this->_class;
			$this->_classes = array( $this->_class );
		}
		else
			$this->_classes = array();
	}

	private function _setupAPP()
	{
	    try {
			//echo "__convertSessionError2HeaderException()";
			include_once $this->_appBoot;
	    }
		catch( Exception $e )
		{
		   	$this->_log( __METHOD__ . ' ' . $e->getMessage() ); 
		}
		$this->_tagfile = $this->_file;
		$this->_sqlView = $this->_tagfile;
		$this->_addSource();
		$this->_grepClassName();
		if ($this->_class) {
			include_once $this->_tagfile;
			$this->_sqlClass = $this->_class;
			$this->_classes  = array($this->_class);
		}
		else {
            $this->_classes = array();
        }
	}

	private function _filter($keep = 'user')
	{
		$this->_classes = array_diff(get_declared_classes(),
                                     ($keep == 'user' ? $this->_classes
                                                      : array()),
                                     array('PHPTAGS'));

		$this->_functions = $this->_functions[$keep];
	}

	private function _setParams($p)
	{
		foreach ($p as $k => $v) {
			if (property_exists($this, $k = "_$k")) {
                $this->{$k} = $v;
            }
        }
        if (null === $this->_mode) {
            $this->_mode = 'app';
        }
        if (null !== $this->_force) {
            $this->_sqlIgnore = '';
        }
	}

	private function _log( $msg, $data = null )
	{
        if (null === $this->_debug) {
            return;
        }

		print "
#####################################################################
#
# $msg
#
#####################################################################

";
		ob_start();
		( $data ? var_dump( $data ) : '' );
		ob_end_flush();
	}

	private function _addSource()
	{
		if(!isset($this->_sources[$this->_tagfile])) {
            $this->_sources[$this->_tagfile] = file($this->_tagfile);
        }
	}

	private function _grepClassName()
    {
        $class = preg_grep("/^\s*(?:abstract\s+|final\s+)*class\s+\w+\s*(?:extends\s+\w+|implements\s+(?:\w+,?\s*)*)*\{?\s*$/",
                           $this->_sources[$this->_tagfile]);

		$this->_class = trim(preg_replace("/^\s*(?:abstract\s+|final\s+)*class\s+(\w+).*$/",
                                          "$1",
                                          array_shift($class)),
                             "\r\n");
		//$this->_log( __METHOD__, $this->_class );
	}

	private function _grepInterfaceName()
	{
		$interface = preg_grep("/^\s*(?:abstract\s+|final\s+)*class\s+\w+\s*(?:extends\s+\w+|implements\s+(?:\w+,?\s*)*)*\{?\s*$/",
                               $this->_sources[$this->_tagfile]);

		$this->_interface = trim(preg_replace("/^\s*(?:abstract\s+|final\s+)*class\s+(\w+).*$/",
                                              "$1",
                                              array_shift($interface)),
                                 "\r\n");
		//$this->_log( __METHOD__, $this->_interface );
	}

	private function _grepIncludes()
	{
		$inc = preg_grep(
			"/^\s*((include|require)_once)\s+/",
			$this->_sources[$this->_tagfile]
		);
		foreach( $inc as $in )
			include_once( trim( preg_replace(
				"/^\s*(?:(?:include|require)_once)\s+(?:\"?'?([^\s\"']+)\"?'?).*/",
				'$1', $in
			), "\t\r\n " ) );
	}

	private function _grepTagAdress()
	{
		$pattern = $this->_tagname;

		if( $this->_kind == 'constant' )
			$pattern = ".*?const.*?$pattern";

		if( $this->_kind == 'property' )
		{
			$pattern = ".*?\\$$pattern";
		}

		$pattern .= "[\s\=\;]";

		$result = preg_grep( "/$pattern/", $this->_sources[$this->_tagfile] );

		if( count( $result ) > 1 )
		{
			$add = "(?:";
			if( $this->_visibility == 'protected' ||
				$this->_visibility == 'private' )
			{
				$add .= "{$this->_visibility} |";
			}
			if( $this->_static )
				$add .= "static ";
			
			$pattern = ".*?$add)+$pattern";
			$result = preg_grep( "/$pattern/", $result );
		}
		$this->_tagadress = array_shift( $result );
	}

	private function _quoteTagAdress()
	{
		$this->_tagadress = str_replace(
			array("'", '/', "\t", '$'),
			array("''",'\/','\t','\$'),
			trim( $this->_tagadress, "\n\r" )
		);
	}

	private function _resetTagProperties()
	{
		$this->_tagadress = null;
		$this->_visibility = null;
		$this->_global = null;
		$this->_static = null;
		$this->_final = null;
		$this->_abstract = null;
	}

	private function _tagClasses()
	{
		while( (bool) ( $this->_class = current( $this->_classes ) ) )
		{
			//$this->_log( __METHOD__, $this->_class );
			$this->_reflectionClass = new ReflectionClass( $this->_class );
			if( $this->_reflectionClass->isUserDefined() )
			{
				$tagfile = $this->_reflectionClass->getFileName();
				$this->_tagfile = $tagfile;
				$this->_addSource();
			}
			else
			{
				$this->_tagfile = $tagfile = 'PHP';
			}
			$this->_tagClassConstants();
			$this->_tagClassProperties();

			$this->_constructorParams = null;
			$this->_tagClassMethods();

			$this->_tagclass = $this->_class;
			$this->_tagname = $this->_class;
			$this->_tagfile = $tagfile;

			$this->_kind = 'class';
			$this->_resetTagProperties();
			$this->_global = 1;
			$this->_final = $this->_reflectionClass->isFinal() ? 1 : null;
			$this->_abstract = $this->_reflectionClass->isAbstract() ? 1 : null;

			$this->_params = $this->_constructorParams;
			$this->_addTag();

			/*
			$this->_pc = $this->_reflectionClass->getParentClass();

			if( $this->_pc )
			{
				//$this->_log( __METHOD__ . ' Parent Class', $this->_pc->getName() );
				if( !$this->_copyExisting( $this->_pc->getName() ) )
					$this->_classes[] = $this->_pc->getName();
			}
			*/
			next( $this->_classes );
		}
	}

	private function _tagClassConstants()
	{
		$this->_kind = 'constant';

		foreach( array_keys( $this->_reflectionClass->getConstants() ) as $this->_tagname )
		{
			$this->_resetTagProperties();
			$declaringClass = $this->_reflectionClass;
			$this->_tagclass = $this->_class;
			$parentClass = $declaringClass->getParentClass();

			while( $parentClass && $parentClass->hasConstant( $this->_tagname ) )
			{
				$declaringClass = $parentClass;
				$parentClass = $declaringClass->getParentClass();
				$this->_tagclass = $declaringClass->getName();
			}

			if( $declaringClass->isUserDefined() )
			{
				$this->_tagfile = $declaringClass->getFileName();
				$this->_addSource();
				$this->_grepTagAdress();
				$this->_quoteTagAdress();	
			}
			else
			{
				$this->_tagfile = 'PHP';
				$this->_tagadress = '';
			}
			$this->_addTag();
		}
	}

	private function _tagClassProperties()
	{
		$this->_kind = 'property';

		foreach( $this->_reflectionClass->getProperties() as $p )
		{
			$this->_resetTagProperties();
			$this->_tagname = $p->getName();
			$declaringClass = $p->getDeclaringClass();
			$this->_tagclass = $declaringClass->getName();

			if( $p->isPrivate() && $this->_tagclass != $this->_class )
				continue;

			$this->_visibility = $p->isPublic() ? 'public' :
					  ( $p->isPrivate() ? 'private' :
					  ( $p->isProtected() ? 'protected' : '' ) );

			$this->_static = $p->isStatic() ? 1 : null;

			if( $declaringClass->isUserDefined() )
			{
				$this->_tagfile = $declaringClass->getFileName();
				$this->_addSource();
				$this->_grepTagAdress();
				$this->_quoteTagAdress();
			}
			else
			{
				$this->_tagfile = 'PHP';
				$this->_tagadress = '';
			}

			$this->_addTag();
		}
	}

	private function _tagClassMethods()
	{
		$this->_kind = 'method';

		foreach( $this->_reflectionClass->getMethods() as $m )
		{
			$this->_resetTagProperties();
			$this->_tagname = $m->getName();
			$declaringClass = $m->getDeclaringClass();
			$this->_tagclass = $declaringClass->getName();

			if( $m->isPrivate() && $this->_tagclass != $this->_class )
				continue;

			$this->_visibility = $m->isPublic()    ? 'public'    :
					  ( $m->isPrivate()   ? 'private'   :
					  ( $m->isProtected() ? 'protected' : '' ) );

			$this->_final = $m->isFinal()     ? 1 : null;
			$this->_static = $m->isStatic()    ? 1 : null;
			$this->_abstract = $m->isAbstract()  ? 1 : null;

			if( $m->isUserDefined() )
			{
				$this->_tagfile = $declaringClass->getFileName();
				$this->_addSource();
				$this->_tagadress = $this->_sources[$this->_tagfile][$m->getStartLine()-1];
				$this->_quoteTagAdress();	
			}
			else
			{
				$this->_tagfile = 'PHP';
				$this->_tagadress = '';
			}

			$this->_reflectParams( $m );

			$this->_addTag();

			if( $m->isConstructor() && $this->_tagclass == $this->_class )
				$this->_constructorParams = $this->_params;
		}
	}

	private function _reflectParams( $m )
	{
		$this->_params = "(";
		foreach( $m->getParameters() as $mp )
		{
			$mpdv = null;
			$mpdvt = null;
			$mpdvc = null;
			$o = $mp->isOptional();
			if( $mp->isDefaultValueAvailable() )
			{
				$mpdv = $mp->getDefaultValue();
				$mpdvt = gettype( $mpdv );
				switch( $mpdvt )
				{
				case 'boolean':
					$mpdvt = 'bool';
					break;
				case 'integer':
					$mpdvt = 'int';
					break;
				}
			}

			$mpc = null;
			try{
				$mpc = $mp->getClass();
			}
			catch( Exception $e ){}

				if( $mpc )
				{
					$mpdvc = $mpc->getName();
				}
				if( $o )
					$this->_params .= '[';
				if( $mp->getPosition() )
					$this->_params .= ',';
				$this->_params .= ' ';
				if( $mpdvt && $mpdvt != 'NULL' )
					$this->_params .= "$mpdvt ";
				if( $mpdvc )
					$this->_params .= "$mpdvc ";
				if( $mp->isPassedByReference() )
					$this->_params .= '&';
				$this->_params .= '$' . $mp->getName();
				if( $mpdvt )
				{
					switch( $mpdvt )
					{
					case 'NULL':
						$mpdv = 'null';
						break;
					case 'array':
						$mpdv = str_replace( "\n", "", var_export( $mpdv, true ) );
						break;
					case 'bool':
						$mpdv = $mpdv ? 'true' : 'false';
						break;
					case 'string':
						$mpdv = "'" . $mpdv . "'";
						break;
					default:
						$mpdv = (string) $mpdv;
					}
					//$this->_params .= '=' . addcslashes( str_replace( "\n", '\n', $mpdv ), "'");
					$this->_params .= '=' . str_replace(
						array( "'", "\n"),
						array( "''",'\n'),
						$mpdv
					);
				}
				$this->_params .= ' ';
				if( $o )
					$this->_params .= ']';
		}
		$this->_params .= ')';
	}

	private function _tagFunctions()
	{
	}
}

$p = $_REQUEST;

for( $i = 1; $i < $argc; $i++ )
{
	parse_str( $argv[$i], $tmp );
	$p = array_merge_recursive( $p, $tmp );
}

$phptags = new PHPTAGS( $p );
$phptags->run();
