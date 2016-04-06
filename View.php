<?php
namespace core\view;
class View {
    /**
     * 模板解析左分隔符
     * @param string
     */
    public $left_delimiter = '{';

    /**
     * 模板解析右分隔符
     * @param string
     */
    public $right_delimiter = '}';

    /**
     * 是否运行模板内插入PHP代码
     * @param bool
     */
    public $php_off = TRUE;

    /**
     * 自动创建子目录
     * @param bool
     */
    public $use_sub_dirs = TRUE;

    /**
     * 是否压缩模板
     * @param bool
     */
    public $strip_space = true;

    /**
     * Gzip数据压缩传输
     * @param bool
     */
    public $header_gzip = true;

    /**
     * 模板缓存过期时间;为-1，则设置缓存永不过期;0可以让缓存每次都重新生成
     * @param int
     */
    public $cache_lifetime = 0;

    /**
     * 编译目录
     * @param string
     */
    public $compile_dir = '';

    /**
     * 缓存目录
     * @param string
     */
    public $cache_dir = '';

    /**
     * 模板目录
     * @param string
     */
    private $view_dir = [];

    /**
     * 模板风格
     * @param string
     */
    public $style = '';

    /**
     * 模板后缀
     * @param string
     */
    public $suffix = '.php';


    /**
     * 缓存文件后缀
     * @var string
     */
    public $cache_suffix = '.cache.php';

    /**
     * 编译文件后缀
     * @var string
     */
    public $compile_suffix = '.compile.php';
    //模板变量
    public $_vars = [];
    public $_left_delimiter, $_right_delimiter;

    public function __construct() {
        //初始化目录
        $this->compile_dir = ROOT_PATH . '/data/view/compile';
        $this->cache_dir = ROOT_PATH . '/data/view/cache';
        if (defined('MODULE')) {
            //模块视图文件夹
            if (is_dir(APP_PATH.'/'.MODULE.'/'.CONTROLLER)) {
                $this->view_dir[] = APP_PATH.'/'.MODULE.'/'.CONTROLLER;
            }
            if (is_dir(APP_PATH.'/'.MODULE)) {
                $this->view_dir[] = APP_PATH.'/'.MODULE;
            }
        }
        if (DEBUG) {
            $this->strip_space = false;
        }
    }

    /**
     * 增加模板目录
     * @param string $dir
     * @return \feros\view
     */
    public function add_view_dir($dir) {
        $dir = realpath($dir);
        if ($dir && is_dir($dir))
            $this->view_dir[] = $dir;
        return $this;
    }

    /**
     * 设置编译目录
     * @param string $dir
     * @return \feros\view
     */
    public function set_compile_dir($dir) {
        $dir = realpath($dir);
        if ($dir && is_dir($dir))
            $this->compile_dir = $dir;
        return $this;
    }

    /**
     * 设置缓存目录
     * @param string $dir
     * @return \feros\view
     */
    public function set_cache_dir($dir) {
        $dir = realpath($dir);
        if ($dir && is_dir($dir))
            $this->cache_dir = $dir;
        return $this;
    }

    /*
     * 渲染模板
     * @param string $view 模板
     * @param array $data 数据
     * @param int $expire 过期时间
     * @param bool|true $show 显示或返回
     * @return string
     * @throws Exception
     */
    public function render($template='', $data='', $return_cache=false, $cache_id=''){
        $content = $this->fetch($template, $data, $return_cache, $cache_id);
        die($content);
    }

    public function fetch($template='', $data='', $return_cache=false, $cache_id=''){
        //变量赋值
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $this->_vars[$k] = $v;
            }
        }

        $this->get_template_file($template);

        if ($return_cache && $this->is_cached($template, $cache_id)){
            return file_get_contents($this->get_cache_file($template, $cache_id));
        }

        if (!is_readable($template)){
            return true;
        }

        ob_start();
        ob_implicit_flush(0);
        extract($this->_vars, EXTR_OVERWRITE);
        include $this->compile($template,  $return_cache, $cache_id);
        $content = ob_get_clean();
        if ($this->header_gzip)
            $this->ob_gzip($content);
        if ($return_cache) {
            $cache = $this->get_cache_file($template, $cache_id);
            if (!$this->is_cached($template, $cache_id)) {
                //保存缓存
                $this->mk_dir(dirname($cache));
                file_put_contents($cache, $content);
            }
        }

        return $content;
    }


    /**
     * 检测缓存是否存在
     * @access public
     * @param string $template 指定要调用的模板文件
     * @param string $cache_id 缓存ID
     * @return boolean
     */
    public function is_cached($template = NULL, $cache_id = NULL) {
        static $cache = [];

        $key = md5($template . $cache_id);
        if (isset($cache[$key]))
            return $cache[$key];

        $c = $this->get_cache_file($template, $cache_id);

        if (!is_readable($c) || $this->cache_lifetime === 0)
            return $cache[$key] = FALSE;

        if ($this->cache_lifetime === -1)
            return $cache[$key] = TRUE;
        $file_time = filemtime($c);
        if (($file_time + $this->cache_lifetime) < time())
            return $cache[$key] = FALSE;
        return $cache[$key] = TRUE;
    }

    /**
     * 编译模板
     * @param string $template 指定要调用的模板文件
     * @return void
     */
    public function compile($template, $return_cache = FALSE, $cache_id = NULL) {

        $compile = $this->get_compile_file($template, $cache_id);

        if (is_readable($compile)) {
            $savet = filemtime($template);
            $fromt = filemtime($compile);
            if ($savet <= $fromt) {
                return $compile;
            }
        }
        //处理分隔符
        $this->_left_delimiter = preg_quote($this->left_delimiter);
        $this->_right_delimiter = preg_quote($this->right_delimiter);

        $content = file_get_contents($template);

        //开始编译
        new Compile($this, $content);
        //保存编译
        $this->mk_dir(dirname($compile));
        file_put_contents($compile, $content);

        return $compile;
    }

    /**
     * Gzip数据压缩传输 如果客户端支持
     * @param string $content
     * @return string
     */
    public function ob_gzip(&$content) {
        if (!headers_sent() && extension_loaded("zlib") && strstr($_SERVER["HTTP_ACCEPT_ENCODING"], "gzip")) {
            $content = gzencode($content, 9);
            header('Content-Encoding:gzip');
            header('Vary:Accept-Encoding');
            header('Content-Length:' . strlen($content));
        }
        return $content;
    }

    /**
     * 返回缓存文件
     * @param string $template 指定要调用的模板文件
     * @param string $cache_id 缓存ID
     * @return string
     */
    public function get_cache_file($template, $cache_id = NULL) {
        return rtrim($this->cache_dir, '\\//') . DS . $this->resolve_file($template, $cache_id) . $this->cache_suffix;
    }

    /**
     * 返回编译文件
     * @param string $template 指定要调用的模板文件
     * @param string $cache_id 缓存ID
     * @return string
     */
    public function get_compile_file($template, $cache_id = NULL) {
        return rtrim($this->compile_dir, '\\//') . DS . $this->resolve_file($template, $cache_id) . $this->compile_suffix;
    }

    /**
     * 解释引擎文件
     * @access public
     * @param string $template
     * @param string $cache_id
     * @return string
     */
    public function resolve_file($template, $cache_id = NULL) {
        static $resolve = [];
        $template = md5($template . $cache_id);
        if (isset($resolve[$template]))
            return $resolve[$template];
        if ($this->use_sub_dirs) {
            $dir = '';
            for ($i = 0; $i < 6; $i++)
                $dir .= ($template{$i}) . ($template{ ++$i}) . DS;
            $template = $dir . md5($template);
        }
        return $resolve[$template] = $template;
    }

    /**
     *  获取模板文件
     * @access public
     * @param string $template  模板
     * @return string
     */
    public function get_template_file(&$template) {

        if (is_readable($template))
            return $template;
        $template = str_replace('\\', DS, $template);

        foreach ($this->view_dir as $value) {
            $tpl = rtrim($value, '\\//') . DS . ($this->style ? trim($this->style, '\\//') . DS : '') . $template . $this->suffix;
            if (is_readable($tpl))
                return $template = $tpl;
        }

        throw new \Exception('template_not_exist');
    }

    /**
     * 创建目录
     * 
     * @param	string	$path	路径
     * @param	string	$mode	属性
     * @return	string	如果已经存在则返回FALSE，否则为flase
     */
    public function mk_dir($path, $mode = 0777) {
        if (is_dir($path))
            return TRUE;
        $_path = dirname($path);
        if ($_path !== $path)
            $this->mk_dir($_path, $mode);
        return @mkdir($path, $mode);
    }
}
