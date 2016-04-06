<?php
namespace core\view;

/**
 * 模板解析
 * @author sanliang
 */
class Compile {

    public $view;
    private $_block = [], $_template_preg = [], $_template_replace = [];

    public function __construct(View $view, &$content) {
        $this->view = $view;

        $this->compile_layout($content);
        $this->compile_var($content);
        $this->compile_php();
        $this->compile_html($content);
        $this->compile_code();

        $content = preg_replace($this->_template_preg, $this->_template_replace, $content);
        if ($this->view->strip_space) {
            $content = preg_replace(array('~>\s+<~', '~>(\s+\n|\r)~'), array('><', '>'), $content);
            $content = str_replace('?><?php', '', $content);
        }

        return $content;
    }

    /**
     * 解析标签
     * @param array $content
     * @return string
     */
    private function parse_tag($content) {
        $content = stripslashes($content[0]);
        $content = preg_replace_callback('/\$\w+((\.\w+)*)?/', array($this, 'parse_var'), $content);
        return $content;
    }

    /**
     * 解析变量
     * @param array $var
     * @return string
     */
    private function parse_var($var) {
        if (empty($var[0]))
            return;

        $vars = explode('.', $var[0]);
        $var = array_shift($vars);
        $name = $var;
        foreach ($vars as $val)
            $name .= '["' . trim($val) . '"]';
        return $name;
    }

    private function parse_load($content) {
        $file = $content[1];
        $parse = '';
        
        $array = explode(',', $file);
        foreach ($array as $val) {
            $type = $reset = strtolower(substr(strrchr($val, '.'), 1));
            switch ($type) {
                case 'js':
                    $parse .= '<script type="text/javascript" src="' . $val . '"></script>';
                    break;
                case 'css':
                    $parse .= '<link rel="stylesheet" type="text/css" href="' . $val . '" />';
                    break;
                case 'icon':
                    $parse .= '<link rel="shortcut icon" href="' . $val . '" />';
                    break;
                case 'php':
                    $parse .= '<?php include_once("' . $val . '"); ?>';
                    break;
            }
        }
        return $parse;
    }

    /**
     * 解析布局
     * @param array $content
     * @return string
     */
    private function compile_layout(&$content) {
        $find = preg_match('/' . $this->view->_left_delimiter . 'layout\sname=[\'"](.+?)[\'"]\s*?' . $this->view->_right_delimiter . '/is', $content, $matches);
        if ($find) {
            $content = str_replace($matches[0], '', $content);
            preg_replace_callback('/' . $this->view->_left_delimiter . 'block\sname=[\'"](.+?)[\'"]\s*?' . $this->view->_right_delimiter . '(.*?)' . $this->view->_left_delimiter . '\/block' . $this->view->_right_delimiter . '/is', array($this, 'parse_block'), $content);
            $content = $this->replace_block(file_get_contents($this->view->get_template_file($matches[1])));
        } else {
            $content = preg_replace_callback('/' . $this->view->_left_delimiter . 'block\sname=[\'"](.+?)[\'"]\s*?' . $this->view->_right_delimiter . '(.*?)' . $this->view->_left_delimiter . '\/block' . $this->view->_right_delimiter . '/is', function($match) {
                return stripslashes($match[2]);
            }, $content);
        }
        return $content;
    }

    /**
     * 记录当前页面中的block标签
     * @access private
     * @param string $name block名称
     * @param string $content  模板内容
     * @return string
     */
    private function parse_block($name, $content = '') {
        if (is_array($name)) {
            $content = $name[2];
            $name = $name[1];
        }
        $this->_block[$name] = $content;
        return '';
    }

    private function replace_block($content) {
        static $parse = 0;
        $reg = '/(' . $this->view->_left_delimiter . 'block\sname=[\'"](.+?)[\'"]\s*?' . $this->view->_right_delimiter . ')(.*?)' . $this->view->_left_delimiter . '\/block' . $this->view->_right_delimiter . '/is';
        if (is_string($content)) {
            do {
                $content = preg_replace_callback($reg, array($this, 'replace_block'), $content);
            } while ($parse && $parse--);
            return $content;
        } elseif (is_array($content)) {
            if (preg_match('/' . $this->view->_left_delimiter . 'block\sname=[\'"](.+?)[\'"]\s*?' . $this->view->_right_delimiter . '/is', $content[3])) {
                $parse = 1;
                $content[3] = preg_replace_callback($reg, array($this, 'replace_block'), "{$content[3]}{$this->view->_left_delimiter}/block{$this->view->_right_delimiter}");
                return $content[1] . $content[3];
            } else {
                $name = $content[2];
                $content = $content[3];
                $content = isset($this->_block[$name]) ? $this->_block[$name] : $content;
                return $content;
            }
        }
    }

    private function compile_php() {
        if (!$this->view->php_off) {
            $this->_template_preg[] = '/<\?(=|php|)(.+?)\?>/is';
            $this->_template_replace[] = '&lt;?\\1\\2?&gt;';
        } else {
            $this->_template_preg[] = '/(<\?(?!php|=|$))/i';
            $this->_template_replace[] = '<?php echo \'\\1\'; ?>';
        }
    }

    private function compile_var(&$content) {
        $content = preg_replace_callback('/(' . $this->view->_left_delimiter . ')([^\d\s].+?)(' . $this->view->_right_delimiter . ')/is', array($this, 'parse_tag'), $content);
    }

    private function compile_code() {
        $this->_template_preg[] = '/' . $this->view->_left_delimiter . '(else if|elseif) (.*?)' . $this->view->_right_delimiter . '/i';
        $this->_template_preg[] = '/' . $this->view->_left_delimiter . 'for (.*?)' . $this->view->_right_delimiter . '/i';
        $this->_template_preg[] = '/' . $this->view->_left_delimiter . 'while (.*?)' . $this->view->_right_delimiter . '/i';
        $this->_template_preg[] = '/' . $this->view->_left_delimiter . '(loop|foreach) (.*?)' . $this->view->_right_delimiter . '/i';
        $this->_template_preg[] = '/' . $this->view->_left_delimiter . 'if (.*?)' . $this->view->_right_delimiter . '/i';
        $this->_template_preg[] = '/' . $this->view->_left_delimiter . 'else' . $this->view->_right_delimiter . '/i';
        $this->_template_preg[] = '/' . $this->view->_left_delimiter . "(eval|_)( |[\r\n])(.*?)" . $this->view->_right_delimiter . '/is';
        $this->_template_preg[] = '/' . $this->view->_left_delimiter . '_e (.*?)' . $this->view->_right_delimiter . '/is';
        $this->_template_preg[] = '/' . $this->view->_left_delimiter . '_p (.*?)' . $this->view->_right_delimiter . '/i';
        $this->_template_preg[] = '/' . $this->view->_left_delimiter . '\/(if|for|loop|foreach|eval|while)' . $this->view->_right_delimiter . '/i';
        $this->_template_preg[] = '/' . $this->view->_left_delimiter . '((( *(\+\+|--) *)*?\!?(([_a-zA-Z][\w]*\(.*?\))|\$((\w+)((\[|\()(\'|")?\$*\w*(\'|")?(\)|\]))*((->)?\$?(\w*)(\((\'|")?(.*?)(\'|")?\)|))){0,})( *\.?[^ \.]*? *)*?){1,})' . $this->view->_right_delimiter . '/i';
        $this->_template_preg[] = "/(	| ){0,}(\r\n){1,}\";/";
        $this->_template_preg[] = '/' . $this->view->_left_delimiter . '(\#|\*)(.*?)(\#|\*)' . $this->view->_right_delimiter . '/';
        $this->_template_preg[] = '/' . $this->view->_left_delimiter . 'view\sname=[\'"](.+?)[\'"]\s*?' . $this->view->_right_delimiter . '/';
        $this->_template_replace[] = '<?php }else if (\\2){ ?>';
        $this->_template_replace[] = '<?php for (\\1) { ?>';
        $this->_template_replace[] = '<?php while (\\1) { ?>';
        $this->_template_replace[] = '<?php foreach (\\2) {?>';
        $this->_template_replace[] = '<?php if (\\1){ ?>';
        $this->_template_replace[] = '<?php }else{ ?>';
        $this->_template_replace[] = '<?php \\3; ?>';
        $this->_template_replace[] = '<?php echo \\1; ?>';
        $this->_template_replace[] = '<?php print_r(\\1); ?>';
        $this->_template_replace[] = '<?php } ?>';
        $this->_template_replace[] = '<?php echo \\1;?>';
        $this->_template_replace[] = '';
        $this->_template_replace[] = '';
        $this->_template_replace[] = '<?php echo $this->fetch("\\1");?>';
    }

    private function compile_html(&$content) {
        $content = preg_replace_callback('/' . $this->view->_left_delimiter . 'load\shref=[\'"](.+?)[\'"]\s*?' . $this->view->_right_delimiter . '/is', array($this, 'parse_load'), $content);
 
        /**
         * $this->_template_preg[] = '/' . $this->view->_left_delimiter . 'load\shref=[\'"](.+?)[\'"]\s*?' . $this->view->_right_delimiter . '/';
         * $this->_template_replace[] = '<?php echo $this->parse_load("\\1");?>';
         */
    }

}
