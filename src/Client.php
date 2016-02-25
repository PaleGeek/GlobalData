<?php
namespace GlobalData;
/**
 *  Global data client.
 *  @version 1.0.0
 */
class Client 
{
    /**
     * Timeout.
     * @var int
     */
    public $timeout = 5;
    
    /**
     * Global data server address.
     * @var array
     */
    protected $_globalServers = array();
    
    /**
     * Connection to global server.
     * @var resource
     */
    protected $_globalConnections = null;
    
    /**
     * Cache.
     * @var array
     */
    protected $_cache = array();
    
    /**
     * Construct.
     * @param array/string $servers
     */
    public function __construct($servers)
    {
        if(empty($servers))
        {
            throw new \Exception('servers empty');
        }
        $this->_globalServers = array_values((array)$servers);
    }

    /**
     * Connect to global server.
     * @throws \Exception
     */
    protected function getConnection($key)
    {
        $offset = crc32($key)%count($this->_globalServers);
        if($offset < 0)
        {
            $offset = -$offset;
        }
        
        if(isset($this->_globalConnections[$offset]))
        {
            continue;
        }
        $connection = stream_socket_client("tcp://{$this->_globalServers[$offset]}", $code, $msg, 5);
        if(!$connection)
        {
            throw new \Exception($msg);
        }
        stream_set_timeout($connection, 5);
        if(class_exists('Workerman\Lib\Timer'))
        {
            Workerman\Lib\Timer::add(25, function($connection)
            {
                fwrite($connection, pack('N', 8)."ping");
            }, array($connection));
        }
        $this->_globalConnections[$offset] = $connection;
    }
    

    /**
     * Magic methods __set.
     * @param string $key
     * @param mixed $value
     * @throws \Exception
     */
    public function __set($key, $value) 
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote(array(
           'cmd'   => 'set',
           'key'   => $key,
           'value' => serialize($value),
        ), $connection);
        $this->readFromRemote($connection);
    }

    /**
     * Magic methods __isset.
     * @param string $key
     */
    public function __isset($key)
    {
        return null !== $this->__get($key);
    }

    /**
     * Magic methods __unset.
     * @param string $key
     * @throws \Exception
     */
    public function __unset($key) 
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote(array(
           'cmd' => 'delete',
           'key' => $key
        ), $connection);
        $this->readFromRemote($connection);
    }
  
    /**
     * Magic methods __get.
     * @param string $key
     * @throws \Exception
     */
    public function __get($key)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote(array(
           'cmd' => 'get',
           'key' => $key,
        ), $connection);
        return unserialize($this->readFromRemote($connection));
    }

    /**
     * Cas.
     * @param string $key
     * @param mixed $value
     * @throws \Exception
     */
    public function cas($key, $old_value, $new_value)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote(array(
           'cmd'     => 'cas',
           'md5' => md5(serialize($old_value)),
           'key'     => $key,
           'value'   => serialize($new_value),
        ),$connection);
        return "ok" === $this->readFromRemote($connection);
    }

    /**
     * Write data to global server.
     * @param string $buffer
     */
    protected function writeToRemote($data, $connection)
    {
        $buffer = serialize($data);
        $buffer = pack('N',4 + strlen($buffer)) . $buffer;
        $len = fwrite($connection, $buffer);
        if($len !== strlen($buffer))
        {
            throw new \Exception('writeToRemote fail');
        }
    }
    
    /**
     * Read data from global server.
     * @throws Exception
     */
    protected function readFromRemote($connection)
    {
        $all_buffer = '';
        $total_len = 4;
        $head_read = false;
        while(1)
        {
            $buffer = fread($connection, 8192);
            if($buffer === '' || $buffer === false)
            {
                throw new \Exception('readFromRemote fail');
            }
            $all_buffer .= $buffer;
            $recv_len = strlen($all_buffer);
            if($recv_len >= $total_len)
            {
                if($head_read)
                {
                    break;
                }
                $unpack_data = unpack('Ntotal_length', $all_buffer);
                $total_len = $unpack_data['total_length'];
                if($recv_len >= $total_len)
                {
                    break;
                }
                $head_read = true;
            }
        }
        return substr($all_buffer, 4);
    }
}
