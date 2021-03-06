<?php
/**
 * @package midgardmvc_core
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

// We use the PEAR WebDAV server class
require 'HTTP/WebDAV/Server.php';

// The PATH_INFO needs to be provided so that creates will work
$_SERVER['PATH_INFO'] = midgardmvc_core::get_instance()->context->uri;
$_SERVER['SCRIPT_NAME'] = midgardmvc_core::get_instance()->context->prefix;

/**
 * WebDAV server for Midgard MVC
 *
 * @package midgardmvc_core
 */
class midgardmvc_core_helpers_webdav extends HTTP_WebDAV_Server
{
    private $locks = array();
    private $data = array();
    private $dispatcher = null;
    
    public function __construct()
    {
        $this->data = midgardmvc_core::get_instance()->context->get();
        parent::HTTP_WebDAV_Server();
    }

    /**
     * Serve a WebDAV request
     *
     * @access public
     */
    public function serve() 
    {
        // special treatment for litmus compliance test
        // reply on its identifier header
        // not needed for the test itself but eases debugging
        if (function_exists('apache_request_headers'))
        {
            foreach(apache_request_headers() as $key => $value) 
            {
                if (stristr($key, 'litmus'))
                {
                    error_log("Litmus test {$value}");
                    midgardmvc_core::get_instance()->dispatcher->header("X-Litmus-reply: {$value}");
                }
            }
        }

        midgardmvc_core::get_instance()->log(__CLASS__ . '::' . __FUNCTION__, "\n\n=================================================");
        midgardmvc_core::get_instance()->log(__CLASS__ . '::' . __FUNCTION__, "Serving {$_SERVER['REQUEST_METHOD']} request for {$_SERVER['REQUEST_URI']}");
        
        midgardmvc_core::get_instance()->dispatcher->header("X-Dav-Method: {$_SERVER['REQUEST_METHOD']}");
        
        // let the base class do all the work
        parent::ServeRequest();
        midgardmvc_core::get_instance()->log(__CLASS__ . '::' . __FUNCTION__, "Path was: {$this->path}");
        die();
    }

    /**
     * OPTIONS method handler
     *
     * The OPTIONS method handler creates a valid OPTIONS reply
     * including Dav: and Allowed: headers
     * based on the route configuration
     *
     * @param  void
     * @return void
     */
    function http_OPTIONS() 
    {
        // Microsoft clients default to the Frontpage protocol 
        // unless we tell them to use WebDAV
        midgardmvc_core::get_instance()->dispatcher->header("MS-Author-Via: DAV");

        // tell clients what we found
        $this->http_status("200 OK");
        
        // We support DAV levels 1 & 2
        // midgardmvc_core::get_instance()->dispatcher->header("DAV: 1, 2"); TODO: Re-enable when we support locks
        midgardmvc_core::get_instance()->dispatcher->header("DAV: 1, 2");
        
        midgardmvc_core::get_instance()->dispatcher->header("Content-length: 0");
    }

    /**
     * PROPFIND method handler
     *
     * @param  array  general parameter passing array
     * @param  array  return array for file properties
     * @return bool   true on success
     */
    function PROPFIND(&$options, &$files) 
    {
        $this->filename_check();

        midgardmvc_core::get_instance()->authorization->require_user();
        
        if (!isset($this->data['children']))
        {
            // Controller did not return children
            $this->data['children'] = $this->get_node_children(midgardmvc_core::get_instance()->context->page);
        }
        
        if (empty($this->data['children']))
        {
            return false;
        }
        
        // Convert children to PROPFIND elements
        $this->children_to_files($this->data['children'], $files);
        
        return true;
    }

    private function children_to_files($children, &$files)
    {
        $files['files'] = array();

        foreach ($children as $child)
        {
            $child_props = array
            (
                'props' => array(),
                'path'  => $child['uri'],
            );
            $child_props['props'][] = $this->mkprop('displayname', $child['title']);
            $child_props['props'][] = $this->mkprop('getcontenttype', $child['mimetype']);
            
            if (isset($child['resource']))
            {
                $child_props['props'][] = $this->mkprop('resourcetype', $child['resource']);
            }

            if (isset($child['size']))
            {
                $child_props['props'][] = $this->mkprop('getcontentlength', $child['size']);
            }

            if (isset($child['revised']))
            {
                $child_props['props'][] = $this->mkprop('getlastmodified', strtotime($child['revised']));
            }

            $files['files'][] = $child_props;
        }
    }

    private function get_node_children(midgardmvc_core_node $node)
    {
        // Load children for PROPFIND purposes
        $children = array();
        $mc = midgardmvc_core_node::new_collector('up', $node->id);
        $mc->set_key_property('name');
        $mc->add_value_property('title');
        $mc->execute(); 
        $pages = $mc->list_keys();
        foreach ($pages as $name => $array)
        {
            if (empty($name))
            {
                continue;
            }
            $children[] = array
            (
                'uri'      => "{midgardmvc_core::get_instance()->context->prefix}{$name}/", // FIXME: dispatcher::generate_url
                'title'    => $mc->get_subkey($name, 'title'),
                'mimetype' => 'httpd/unix-directory',
                'resource' => 'collection',
            );
        }
        
        if (midgardmvc_core::get_instance()->context->page->id == midgardmvc_core::get_instance()->context->root_page->id)
        {
            // Additional "special" URLs
            $children[] = array
            (
                'uri'      => "{midgardmvc_core::get_instance()->context->prefix}mgd:snippets/", // FIXME: dispatcher::generate_url
                'title'    => 'Code Snippets',
                'mimetype' => 'httpd/unix-directory',
                'resource' => 'collection',
            );
            $children[] = array
            (
                'uri'      => "{midgardmvc_core::get_instance()->context->prefix}mgd:styles/", // FIXME: dispatcher::generate_url
                'title'    => 'Style Templates',
                'mimetype' => 'httpd/unix-directory',
                'resource' => 'collection',
            );
        }

        return $children;
    }

    /**
     * Check filename against some stupidity
     */
    private function filename_check($filename = null)
    {
        if (   $filename == '.DS_Store'
            || substr($filename, 0, 2) == '._')
        {
            midgardmvc_core::get_instance()->log(__CLASS__ . '::' . __FUNCTION__, "Raising 404 for {$filename} because of filename sanity rules");
            throw new midgardmvc_exception_notfound("OS X DotFiles not allowed");
        }
    }

    /**
     * GET method handler
     * 
     * @param  array  parameter passing array
     * @return bool   true on success
     */
    function GET(&$options) 
    {
        $this->filename_check();

        midgardmvc_core::get_instance()->authorization->require_user();
        
        return true;
    }

    /**
     * PUT method handler
     * 
     * @param  array  parameter passing array
     * @return bool   true on success
     */
    function PUT(&$options) 
    {
        $this->filename_check();

        midgardmvc_core::get_instance()->authorization->require_user();
        
        return true;
    }

    /**
     * MKCOL method handler
     *
     * @param  array  general parameter passing array
     * @return bool   true on success
     */
    public function MKCOL($options)
    {
        return '201 Created';
    }

    /**
     * MOVE method handler
     *
     * @param  array  general parameter passing array
     * @return bool   true on success
     */
    function MOVE($options) 
    {
        return true;
    }

    /**
     * COPY method handler
     *
     * @param  array  general parameter passing array
     * @return bool   true on success
     */
    function COPY($options) 
    {
        return true;
    }

    /**
     * DELETE method handler
     *
     * @param  array  general parameter passing array
     * @return bool   true on success
     */
    function DELETE($options) 
    {
        return "204 No Content";
    }


    /**
     * LOCK method handler
     *
     * @param  array  general parameter passing array
     * @return bool   true on success
     */
    function LOCK(&$options) 
    {
        $options['timeout'] = time() + midgardmvc_core::get_instance()->configuration->get('metadata_lock_timeout');

        if (   !isset($this->data['object'])
            || !is_object($this->data['object'])
            || !$this->data['object']->guid)
        {
            throw new midgardmvc_exception_notfound("No lockable objects");
        }

        $shared = false;
        if ($options['scope'] == 'shared')
        {
            $shared = true;
        }
        
        if (is_null($this->data['object']))
        {
            throw new midgardmvc_exception_notfound("Not found");
        }
        
        if (midgardmvc_core_helpers_metadata::is_locked($this->data['object']))
        {
            midgardmvc_core::get_instance()->log(__CLASS__ . '::' . __FUNCTION__, "Object is locked by another user {$this->data['object']->metadata->locker}");
            return "423 Locked";
        }

        midgardmvc_core_helpers_metadata::lock($this->data['object'], $shared, $options['locktoken']);
        
        return "200 OK";
    }
    
    /**
     * UNLOCK method handler
     *
     * @param  array  general parameter passing array
     * @return bool   true on success
     */
    function UNLOCK(&$options) 
    {
        if (   !isset($this->data['object'])
            || !is_object($this->data['object'])
            || !$this->data['object']->guid)
        {
            throw new midgardmvc_exception_notfound("No lockable objects");
        }
        
        if (midgardmvc_core_helpers_metadata::is_locked($this->data['object']))
        {
            midgardmvc_core::get_instance()->log(__CLASS__ . '::' . __FUNCTION__, "Object is locked by another user {$this->data['object']->metadata->locker}");
            return "423 Locked";
        }

        midgardmvc_core::get_instance()->log(__CLASS__ . '::' . __FUNCTION__, "Unlocking");
        midgardmvc_core_helpers_metadata::unlock($this->data['object']);

        return "200 OK";
    }

    /**
     * checkLock() helper
     *
     * @param  string resource path to check for locks
     * @return bool   true on success
     */
    function checkLock($path) 
    {
        midgardmvc_core::get_instance()->log(__CLASS__ . '::' . __FUNCTION__, "CHECKLOCK: {$path}");
        if (isset($this->locks[$path]))
        {
            return $this->locks[$path];
        }
        
        try
        {
            $this->filename_check(basename($path));
        }
        catch (Exception $e)
        {
            // Don't bother checking these types of files for locks
            $this->locks[$path] = false;
            return $this->locks[$path];
        }

        midgardmvc_core::get_instance()->log(__CLASS__ . '::' . __FUNCTION__, "Resolving {$path} for locks using manual dispatcher");
        if (is_null($this->dispatcher))
        {
            $this->dispatcher = new midgardmvc_core_services_dispatcher_manual();
        }
        
        midgardmvc_core::get_instance()->context->create();
        $page = $this->dispatcher->resolve_page($path);
        if (!$page)
        {
            midgardmvc_core::get_instance()->log(__CLASS__ . '::' . __FUNCTION__, "Path {$path} not found");
            $this->locks[$path] = false;
            return $this->locks[$path];

        }
        $this->dispatcher->set_page($page);
        $this->dispatcher->populate_environment_data();
        $this->dispatcher->initialize($component_name);
        // FIXME: Before this we need to figure out the correct route
        $this->dispatcher->dispatch();
        midgardmvc_core::get_instance()->context->delete();

        if (   !isset($this->data['object'])
            || !is_object($this->data['object'])
            || !$this->data['object']->guid)
        {
            midgardmvc_core::get_instance()->log(__CLASS__ . '::' . __FUNCTION__, "Controller for {$path} did not return lockable objects");
            $this->locks[$path] = false;
            return $this->locks[$path];
        }
        

        if (!midgardmvc_core_helpers_metadata::is_locked($this->data['object'], false))
        {
            midgardmvc_core::get_instance()->log(__CLASS__ . '::' . __FUNCTION__, "Not locked, locked = {$this->data['object']->metadata->locked}, locker = {$this->data['object']->metadata->locker}");
            $this->locks[$path] = false;
            return $this->locks[$path];
        }

        // Populate lock info from metadata
        $lock = array
        (
            'type' => 'write',
            'scope' => 'shared',
            'depth' => 0,
            'owner' => $this->data['object']->metadata->locker,
            'created' => strtotime($this->data['object']->metadata->locked  . ' GMT'),
            'modified' => strtotime($this->data['object']->metadata->locked . ' GMT'),
            'expires' => strtotime($this->data['object']->metadata->locked . ' GMT') + midgardmvc_core::get_instance()->configuration->get('metadata_lock_timeout') * 60,
        );
        
        if ($this->data['object']->metadata->locker)
        {
            $lock['scope'] = 'exclusive';
        }
        
        $lock_token = $this->data['object']->parameter('midgardmvc_core_helper_metadata', 'lock_token');
        if ($lock_token)
        {
            $lock['token'] = $lock_token;
        }
        
        midgardmvc_core::get_instance()->log(__CLASS__ . '::' . __FUNCTION__, serialize($lock));

        $this->locks[$path] = $lock;
        return $this->locks[$path];
    }

    /**
     * Handle HTTP Basic authentication using Midgard MVC authentication service
     *
     * @access private
     * @param  string  HTTP Authentication type (Basic, Digest, ...)
     * @param  string  Username
     * @param  string  Password
     * @return bool    true on successful authentication
     */
    function checkAuth($type, $username, $password)
    {
        if (!midgardmvc_core::get_instance()->authentication->is_user())
        {
            if (!midgardmvc_core::get_instance()->authentication->login($username, $password))
            {
                return false;
            }
        }
        return true;
    }
}
?>
