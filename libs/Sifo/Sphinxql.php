<?php
/**
 * LICENSE
 *
 * Copyright 2013 Pablo Ros
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

namespace Sifo;

/**
 * SphinxQL class. Use this class to execute queries against SphinxQL.
 * It Only supports SELECT sentences to execute.
 */
class Sphinxql
{
	/**
	 * @var Sphinxql Current instance.
	 */
	static protected $instance;

	/**
	 * @var Sphinxql Object. The Sphinxql object instance.
	 */
	public $sphinxql;

	/**
	 * @var array Sphinx config params.
	 */
	protected $sphinx_config;

	/**
	 * @var string Multi query string.
	 */
	private $multi_query = '';

	/**
	 * Initialize this class.
	 * @param $profile
	 */
	protected function __construct( $profile )
	{
		$this->sphinx_config = $this->getConnectionParams( $profile );

		// Check if Sphinx is enabled by configuration:
		if ( true === $this->sphinx_config['active'] )
		{
			$this->sphinxql = self::connect( $this->sphinx_config );
		}

		return $this->sphinx_config;
	}

	/**
	 * Singleton get instance. Return one search engine object.
	 *
	 * @param string $profile
	 * @return Sphinxql Config
	 */
	public static function getInstance( $profile = 'default' )
	{
		if ( !isset ( self::$instance[$profile] )  )
		{
			if ( Domains::getInstance()->getDebugMode() !== true )
			{
				self::$instance[$profile] = new Sphinxql( $profile );
			}
			else
			{
				self::$instance[$profile] = new DebugSphinxql( $profile );
			}
		}

		return self::$instance[$profile];
	}

	/**
	 * Get Sphinx connection params from config files.
	 *
	 * @param $profile
	 * @throws Exception_500
	 * @return array
	 */
	protected function getConnectionParams( $profile )
	{
		try
		{
			// If the domains.config doesn't define the params, we use the sphinx.config.
			$sphinx_config = Config::getInstance()->getConfig( 'sphinx' );

			if ( empty( $sphinx_config[$profile] ) )
			{
				throw new \Sifo\Exception_500( "Expected sphinx settings not defined for profile {$profile} in sphinx.config." );
			}

			$sphinx_config = $this->checkBalancedProfile( $sphinx_config[$profile] );
		}
		catch ( Exception_Configuration $e )
		{
			throw new \Sifo\Exception_500( 'You must define the connection params in sphinx.config' );
		}

		return $sphinx_config;
	}

	/**
	 * Check if one profile has balanced servers or single server. Returns the connection to use.
	 * @param $sphinx_config
	 * @return array
	 */
	private function checkBalancedProfile( $sphinx_config )
	{
		if ( isset( $sphinx_config[0] ) && is_array( $sphinx_config[0] ) )
		{
			$lb = new LoadBalancerSphinxql();
			$lb->injectObject( $this );
			$lb->setNodes( $sphinx_config );
			$selected_server = $lb->get();
			$sphinx_config = $sphinx_config[$selected_server];
		}

		return $sphinx_config;
	}

	/**
	 * Use this method to connect to Sphinx.
	 * @param $node_properties
	 * @return \mysqli
	 * @throws Exception_500
	 */
	public function connect( $node_properties )
	{
		$mysqli = mysqli_connect( $node_properties['server'], '', '', '', $node_properties['port'] );

		if ( !$mysqli || $mysqli->connect_error )
		{
			throw new \Sifo\Exception_500( 'Sphinx (' . $node_properties['server'] . ':' . $node_properties['port'] . ') is down!' );
		}

		return $mysqli;
	}

	/**
	 * Executes one query (selects) into sphinxQl.
	 * @param $query
	 * @param $tag
	 * @return array|boolean
	 */
	public function query( $query, $tag = null, $parameters = array() )
	{
		$this->addQuery( $query, $tag, $parameters );

		$results = $this->multiQuery( $tag );

		// If we called this method we expect only one result...
		if ( !empty( $results ) )
		{
			// ...so we pop it from the resultset.
			return array_pop( $results );
		}

		return $results;
	}

	/**
	 * Add query to be executed using the multi query feature.
	 * @param $query
	 * @param null $tag
	 */
	public function addQuery( $query, $tag = null, $parameters = array() )
	{
		// Delete final ; because is the query separator for multi queries.
		$query = preg_replace( '/;+$/', '', $query );
		$this->multi_query .= $this->prepareQuery( $query, $parameters ) . ';';
	}

	/**
	 * Execute all queries added using addQuery method.
	 * @param $tag
	 * @return array|boolean
	 */
	public function multiQuery( $tag = null )
	{
		$final_result = false;
		$response = $this->sphinxql->multi_query( $this->multi_query );

		if ( !$response || $this->sphinxql->errno )
		{
			$this->logError( $this->sphinxql->error );
		}
		else
		{
			do
			{
				if ( $result = $this->sphinxql->store_result() )
				{
					for ( $res = array(); $tmp = $result->fetch_array( MYSQLI_ASSOC ); ) $res[] = $tmp;
					$final_result[] = $res;
					$result->free();
				}
			}
			while ( $this->sphinxql->next_result() );
		}

		$this->multi_query = '';

		return $final_result;
	}

	/**
	 * Some kind of PDO prepared statements simulation.
	 *
	 * Autodetects the parameter type, escapes them and replace the keys in the query. Example:
	 *
	 * 	$sql = 'SELECT * FROM index WHERE tag = :tag_name';
	 * 	$results = $sphinx->query( $sql, 'label', array( ':tag_name' => 'some-tag' ) );
	 *
	 * @param string $query SphinxQL query.
	 * @param array $parameters List of parameters.
	 *
	 * @return string Prepared query.
	 */
	protected function prepareQuery( $query, $parameters )
	{
		if ( empty( $parameters ) )
		{
			return $query;
		}

		foreach ( $parameters as &$parameter )
		{
			if ( is_null( $parameter ) )
			{
				$parameter = 'NULL';
			}
			elseif ( is_int( $parameter ) || is_float( $parameter ) )
			{
				// Locale unaware number representation.
				$parameter = sprintf( '%.12F', $parameter );
				if ( false !== strpos( $parameter, '.' ) )
				{
					$parameter = rtrim( rtrim( $parameter, '0' ), '.' );
				}
			}
			else
			{
				$parameter = "'" . $this->sphinxql->real_escape_string( $parameter ) . "'";
			}
		}

		return strtr( $query, $parameters );
	}

	/**
	 * Return last error generated.
	 * @return mixed
	 */
	public function getError()
	{
		return $this->sphinxql->error;
	}

	/**
	 * Log error in the errors.log file.
	 * @param $error
	 */
	protected function logError( $error )
	{
		trigger_error( '[SphinxQL ERROR] ' . $error );
	}
}

/**
 * Class LoadBalancerSphinxql
 * @package Sifo
 */
class LoadBalancerSphinxql extends LoadBalancer
{
	/**
	 * Name of the cache where the results of server status are stored.
	 * @var string
	 */
	public $loadbalancer_cache_key = '__sphinxql_loadbalancer_available_nodes';

	private $sphinxql_object;

	protected function addNodeIfAvailable( $index, $node_properties )
	{
		try
		{
			$this->sphinxql_object->connect( $node_properties );
			$this->addServer( $index, $node_properties['weight'] );
		}
		catch( \Sifo\Exception_500 $e )
		{
			trigger_error( 'Sphinx (' . $node_properties['server'] . ':' . $node_properties['port'] . ') is down!' );
		}
	}

	public function injectObject( $object )
	{
		$this->sphinxql_object = $object;
	}
}