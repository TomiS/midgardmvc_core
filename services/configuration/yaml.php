<?php
/**
 * @package midgardmvc_core
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

/**
 * YAML-based configuration implementation for Midgard MVC
 *
 * @package midgardmvc_core
 */
class midgardmvc_core_services_configuration_yaml implements midgardmvc_core_services_configuration
{
    private $components = array();
    private $configuration = array();
    private $midgardmvc = null;
    
    private $use_syck = true;
    
    public function __construct()
    {
        // Check for syck extension
        $this->use_syck = extension_loaded('syck');
        if (!$this->use_syck)
        {
            // Syck PHP extension is not loaded, include the pure-PHP implementation
            require_once 'midgardmvc_core/helpers/spyc.php';
        }
    }
    
    public function load_component_configuration($component, $prepend = false)
    {
        if (isset($this->components[$component]))
        {
            return;
        }
        $this->components[$component] = true;

        if (is_null($this->midgardmvc))
        {
            $this->midgardmvc = midgardmvc_core::get_instance();
        }

        // Check for component inheritance
        $components = array
        (
            $component,
        );

        
        if (isset($this->midgardmvc->componentloader))
        {
            while (true)
            {
                $component = $this->midgardmvc->componentloader->get_parent($component);
                if ($component === null)
                {
                    break;
                }

                $components[] = $component;
                $this->components[$component] = true;
            }
            $components = array_reverse($components);
        }

        $config = array();
        foreach ($components as $component)
        {
            // Load component default config from filesystem 
            $component_config = $this->load_file(MIDGARDMVC_ROOT . "/{$component}/configuration/defaults.yml");
            if (!empty($component_config))
            {
                $config = self::merge_configs($config, $component_config);
            }
        }

        if (empty($config))
        {
            return;
        }
       
        if ($prepend)
        {
            $this->configuration = self::merge_configs($config, $this->configuration);
        }
        else
        {
            $this->configuration = self::merge_configs($this->configuration, $config);
        }
    }
    
    public static function merge_configs(array $base, array $extension)
    {
        if (empty($base))
        {
            // Original was empty, no need to merge
            return $extension;
        }
        $merged = $base;
        
        foreach ($extension as $key => $value)
        {
            if (is_array($value)) 
            {
                if (   !isset($merged[$key])
                    || empty($merged[$key])) 
                {
                    // Original was empty, no need to merge
                    $merged[$key] = $value;
                    continue;
                }
                
                $merged[$key] = midgardmvc_core_services_configuration_yaml::merge_configs($merged[$key], $value);
                continue;
            }
            $merged[$key] = $value;
        }
        
        return $merged;
    }

    /**
     * Internal helper of loading configuration array from a file
     *
     * @param string $snippet_path
     * @return array
     */
    private function load_file($file_path)
    {
        if (!file_exists($file_path))
        {
            return array();
        }
        
        $yaml = file_get_contents($file_path);
        $configuration = $this->unserialize($yaml);
        if (!is_array($configuration))
        {
            return array();
        }
        return $configuration;
    }

    /**
     * Internal helper of loading configuration array from a snippet
     *
     * @param string $snippet_path
     * @return array
     */
    private function load_snippet($snippet_path)
    {
        if (is_null($this->midgardmvc))
        {
            $this->midgardmvc = midgardmvc_core::get_instance();
        }

        if (!$this->midgardmvc->dispatcher->get_midgard_connection())
        {
            return array();
        }

        try
        {
            $snippet = new midgard_snippet();
            $snippet->get_by_path($snippet_path);
        }
        catch (Exception $e) 
        {
            return array();
        }
        $configuration = $this->unserialize($snippet->code);
        if (!is_array($configuration))
        {
            return array();
        }
        return $configuration;
    }

    /* 
    private function load_locals()
    {
        $this->locals = array();
        foreach ($this->components as $component)
        {
            $snippet_path = "/local-configuration/{$component}/configuration";
            $components = $this->load_snippet($snippet_path);
            if (empty($this->locals))
            {
                $this->locals = $components;
                continue;
            }
            if (empty($components))
            {
                continue;
            }
            $this->locals = self::merge_configs($this->locals, $components);
        }
    }
    */
 
    /**
     * Load configuration from a Midgard object
     */   
    public function load_object_configuration($object_guid)
    {
        if (is_null($this->midgardmvc))
        {
            $this->midgardmvc = midgardmvc_core::get_instance();
        }

        if (!$this->midgardmvc->dispatcher->get_midgard_connection())
        {
            return;
        }

        $mc = midgard_parameter::new_collector('parentguid', $object_guid);
        $mc->add_constraint('domain', 'IN', $this->components);
        $mc->add_constraint('name', '=', 'configuration');
        $mc->add_constraint('value', '<>', '');
        $mc->set_key_property('guid');
        $mc->add_value_property('value');
        $mc->execute();
        $guids = $mc->list_keys();
        foreach ($guids as $guid => $array)
        {
            $config = $this->unserialize($mc->get_subkey($guid, 'value'));
            if (!is_array($config))
            {
                return;
            }
        }
        
        if (empty($config))
        {
            return;
        }
       
        $this->configuration = self::merge_configs($this->configuration, $config);
    }

    /**
     * Retrieve a configuration key
     *
     * If $key exists in the configuration data, its value is returned to the caller.
     * If the value does not exist, an exception will be raised.
     *
     * @param string $key The configuration key to query.
     * @return mixed Its value
     * @see midgardmvc_helper_configuration::exists()
     */
    public function get($key, $subkey = null)
    {
        if (!$this->exists($key))
        {
            throw new OutOfBoundsException("Configuration key '{$key}' does not exist.");
        }

        if (!is_null($subkey))
        {                      
            if (!isset($this->configuration[$key][$subkey]))
            {
                throw new OutOfBoundsException("Configuration key '{$key}/{$subkey}' does not exist.");
            }

            return $this->configuration[$key][$subkey];
        }

        return $this->configuration[$key];
    }

    public function __get($key)
    {
        return $this->get($key);
    }
    
    public function set_value($key, $value)
    {
        if (   defined('MIDGARDMVC_TEST_RUN')
            && MIDGARDMVC_TEST_RUN)
        {
            $this->configuration[$key] = $value;
        }
    }

    /**
     * Checks for the existence of a configuration key.
     *
     * @param string $key The configuration key to check for.
     * @return boolean True, if the key is available, false otherwise.
     */
    public function exists($key)
    {
        return array_key_exists($key, $this->configuration);
    }

    public function __isset($key)
    {
        return $this->exists($key);
    }

    /**
     * Parses configuration string and returns it in configuration array format
     *
     * @param string $configuration Configuration string
     * @return array The loaded configuration array
     */
    public function unserialize($configuration)
    {
        if (!$this->use_syck)
        {
            return Spyc::YAMLLoad($configuration);
        }

        return syck_load($configuration);
    }
    
    /**
     * Dumps configuration array and returns it as a string
     *
     * @param array $configuration Configuration array     
     * @return string Configuration in string format
     */
    public function serialize(array $configuration)
    {
        if (!$this->use_syck)
        {
            return Spyc::YAMLDump($configuration);
        }

        return syck_dump($configuration);
    }
    
    /**
     * Normalizes routes configuration to include needed data
     *
     * @param array $route routes configuration
     * @return array normalized routes configuration
     */
    public function normalize_routes($routes)
    {
        if (is_null($this->midgardmvc))
        {
            $this->midgardmvc = midgardmvc_core::get_instance();
        }

        foreach ($routes as $identifier => $route)
        {
            // Handle the required route parameters
            if (!isset($route['controller']))
            {
                throw Exception("Route {$identifier} has no controller defined");
            }

            if (!isset($route['action']))
            {
                throw Exception("Route {$identifier} has no action defined");
            }

            if (!isset($route['route']))
            {
                throw Exception("Route {$identifier} has no route path defined");
            }

            // Normalize additional parameters
            if (!isset($route['allowed_methods']))
            {
                // Add default HTTP allowed methods
                $route['allowed_methods'] = array
                (
                    'OPTIONS',
                    'GET',
                    'POST',
                );
            }
            
            if (!$this->midgardmvc->configuration->get('enable_webdav'))
            {
                // Only allow GET and POST
                $route['allowed_methods'] = array
                (
                    'GET',
                    'POST',
                );
            }
            
            if (!isset($route['webdav_only']))
            {
                $route['webdav_only'] = false;
            }
            
            if (!isset($route['template_entry_point']))
            {
                // Add default HTTP allowed methods
                $route['template_entry_point'] = 'ROOT';
            }

            if (!isset($route['content_entry_point']))
            {
                // Add default HTTP allowed methods
                $route['content_entry_point'] = 'content';
            }

            $routes[$identifier] = $route;
        }
        
        return $routes;
    }
    /**
     * Normalizes given route definition ready for parsing
     *
     * @param string $route route definition
     * @return string normalized route
     */
    public function normalize_route_path($route)
    {
        // Normalize route
        if (   strpos($route, '?') === false
            && substr($route, -1, 1) !== '/')
        {
            $route .= '/';
        }
        return preg_replace('%/{2,}%', '/', $route);
    }

    /**
     * Splits a given route (after normalizing it) to it's path and get parts
     *
     * @param string $route reference to a route definition
     * @return array first item is path part, second is get part, both default to boolean false
     */
    public function split_route(&$route)
    {
        $route_path = false;
        $route_get = false;
        $route_args = false;
        
        /* This will split route from "@" - mark
         * /some/route/@somedata
         * $matches[1] = /some/route/
         * $matches[2] = somedata
         */
        preg_match('%([^@]*)@%', $route, $matches);
        
        if(count($matches) > 0)
        {
            $route_args = true;
        }
        
        $route = $this->normalize_route_path($route);
        // Get route parts
        $route_parts = explode('?', $route, 2);
        $route_path = $route_parts[0];
        if (isset($route_parts[1]))
        {
            $route_get = $route_parts[1];
        }
        unset($route_parts);
        return array($route_path, $route_get, $route_args);
    }
}
?>
