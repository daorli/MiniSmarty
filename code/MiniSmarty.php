<?php

/**
 * Class MiniSmarty
 *
 * @author devghb, edit by daorli
 */
class MiniSmarty
{
    public $template = "";         //模板路径
    public $resBase = "";          //静态文件路径如js,css
    public $var = array();         //变量容器

    public $leftDelimiter = '{#';  // 变量左边界
    public $rightDelimiter = '#}'; // 变量右边界
    protected $debug = false;      // 调试模式

    private $_is_cache;            // 是否开启缓存
    private $_cache_dir;           // 缓存目录

    protected $foreachMark   = '';
    protected $foreach       = array();
    protected $tempKey      = array();  // 临时存放 foreach 里 key 的数组
    protected $tempVal      = array();  // 临时存放 foreach 里 item 的数组
    protected $vars          = array();

    /**
     * MiniSmarty constructor.
     *
     * @param bool $isCache
     * @param string $cacheDir
     */
    public function __construct($isCache=false, $cacheDir = __DIR__) {
        $this->_is_cache = $isCache;
        $this->_cache_dir = $cacheDir;

        if (!is_dir($this->_cache_dir)) {
            $path = ltrim($this->_cache_dir, '/');
            $pathinfo = explode('/', $path);

            $dirname = "";
            foreach ($pathinfo AS $dir) {
                $dirname .= "/{$dir}";
                is_dir($dirname) or mkdir($dirname);
            }
        }
        error_reporting(E_ALL^E_NOTICE^E_STRICT);
    }

    /**
     * 注册变量
     *
     * @param mixed $name
     * @param mixed $value
     */
    function assign($name, $value = '')
    {
        if (is_array($name))
        {
            foreach ($name AS $key => &$val)
            {
                if ($key != '')
                {
                    $this->var[$key] = $val;
                }
            }
        }
        else
        {
            if ($name != '')
            {
                $this->var[$name] = $value;
            }
        }
    }

    /**
     * 显示页面
     *
     * @param string $tpl
     *
     * @throws Exception
     */
    public function display($tpl)
    {
        $template = $this->template . '/' .$tpl;
        $cacheFile = rtrim($this->_cache_dir, '/') . '/' . md5('Mini_Smarty' . $this->_cache_dir . $template) . '.tpl';

        if ($this->_is_cache and is_file($cacheFile)) {
            $smarty = file_get_contents($cacheFile);
        } else {
            if (!is_file($template)) {
                throw new \Exception("{$tpl} is not found", 404);
            }

            $smarty = file_get_contents($template);
            $smarty = $this->fetch_str($smarty);

            !is_file($cacheFile) and file_put_contents($cacheFile, $smarty);
        }

        $html = $this->_eval($smarty);
        echo $html;

        if($this->debug) {
            echo "<br>";
            list($tmp1, $tmp2) = explode(" ", ROOT_START);
            list($tmp3, $tmp4) = explode(" ", microtime());
            echo "耗时：秒" . ($tmp4 - $tmp2) . "  |  毫秒" . ($tmp3 - $tmp1);
        }
    }

    /**
     * 处理字符串函数
     *
     * @access  public
     * @param   string     $source
     *
     * @return  string
     */
    function fetch_str($source)
    {
        $preg = "/{$this->leftDelimiter}([^\}\{\n]*){$this->rightDelimiter}/";
        return preg_replace_callback($preg, function($r) {
            return $this->select($r[1]);
        }, $source);
    }

    /**
     * 处理{}标签
     *
     * @param   string      $tag
     *
     * @return  string
     */
    function select($tag)
    {
        $tag = stripslashes(trim($tag));

        if (empty($tag))
        {
            return $this->leftDelimiter . $this->rightDelimiter;
        }
        elseif ($tag{0} == '*' && substr($tag, -1) == '*') // 注释部分
        {
            return '';
        }
        elseif ($tag{0} == '$') // 变量
        {
            $getVal = $this->get_val(substr($tag, 1));
            if(empty($getVal)){
                return "";
            }
            else{
                return '<?php echo ' . $getVal . '; ?>';
            }
        }
        elseif ($tag{0} == '/') // 结束 tag
        {
            switch (substr($tag, 1))
            {
                case 'if':
                    return '<?php endif; ?>';
                    break;

                case 'foreach':
                    if ($this->foreachMark == 'foreachelse')
                    {
                        $output = '<?php endif; unset($_from); ?>';
                    }
                    else
                    {
                        $output = '<?php endforeach; endif; unset($_from); ?>';
                    }
                    $output .= "<?php \$this->popvars();; ?>";

                    return $output;
                    break;

                case 'literal':
                    return '';
                    break;

                default:
                    return $this->leftDelimiter . $tag . $this->rightDelimiter;
                    break;
            }
        }
        else
        {
            $tag_all = explode(' ', $tag);
            $tag_sel = array_shift($tag_all);
            switch ($tag_sel)
            {
                case 'if':

                    return $this->_compile_if_tag(substr($tag, 3));
                    break;

                case 'else':

                    return '<?php else: ?>';
                    break;

                case 'elseif':

                    return $this->_compile_if_tag(substr($tag, 7), true);
                    break;

                case 'foreachelse':
                    $this->foreachMark = 'foreachelse';

                    return '<?php endforeach; else: ?>';
                    break;

                case 'foreach':
                    $this->foreachMark = 'foreach';

                    return $this->_compileforeach_start(substr($tag, 8));
                    break;

                case 'assign':
                    $t = $this->get_para(substr($tag, 7),0);

                    if ($t['value']{0} == '$')
                    {
                        /* 如果传进来的值是变量，就不用用引号 */
                        $tmp = '$this->assign(\'' . $t['var'] . '\',' . $t['value'] . ');';
                    }
                    else
                    {
                        $tmp = '$this->assign(\'' . $t['var'] . '\',\'' . addcslashes($t['value'], "'") . '\');';
                    }
                    // $tmp = $this->assign($t['var'], $t['value']);

                    return '<?php ' . $tmp . ' ?>';
                    break;

                case 'include':
                    $t = $this->get_para(substr($tag, 8), 0);
                    return '<?php echo $this->fetch(' . "'$t[file]'" . '); ?>';
                    break;
                case 'res':
                    $t = $this->get_para(substr($tag, 4), 0);
                    return '<?php echo $this->resBase . "/" . ' . "'$t[file]'" . '; ?>';
                    break;
                default:
                    return $this->leftDelimiter . $tag . $this->rightDelimiter;
                    break;
            }
        }
    }
    /**
     * 处理smarty标签中的变量标签
     *
     * @param   string   $val 标签
     *
     * @return  bool
     */
    function get_val($val)
    {
        if (strrpos($val, '[') !== false)
        {
            $val = preg_replace("/\[([^\[\]]*)\]/eis", "'.'.str_replace('$','\$','\\1')", $val);
        }

        if (strrpos($val, '|') !== false)
        {
            $moddb = explode('|', $val);
            $val = array_shift($moddb);
        }

        if (empty($val))
        {
            return '';
        }

        if (strpos($val, '.$') !== false)
        {
            $all = explode('.$', $val);

            foreach ($all AS $key => $val)
            {
                $all[$key] = $key == 0 ? $this->makevar($val) : '['. $this->makevar($val) . ']';
            }
            $p = implode('', $all);
        }
        else
        {
            $p = $this->makevar($val);
        }

        if (!empty($moddb))
        {
            foreach ($moddb AS $key => &$mod)
            {
                $s = explode(':', $mod);
                switch ($s[0])
                {
                    case 'escape':
                        $s[1] = trim($s[1], '"');
                        if ($s[1] == 'html')
                        {
                            $p = 'htmlspecialchars(' . $p . ')';
                        }
                        elseif ($s[1] == 'url')
                        {
                            $p = 'urlencode(' . $p . ')';
                        }
                        elseif ($s[1] == 'quotes')
                        {
                            $p = 'addslashes(' . $p . ')';
                        }
                        elseif ($s[1] == 'input')
                        {
                            $p = 'str_replace(\'"\', \'&quot;\',' . $p . ')';
                        }
                        elseif ($s[1] == 'editor')
                        {
                            $p = 'html_filter(' . $p . ')';
                        }
                        else
                        {
                            $p = 'htmlspecialchars(' . $p . ')';
                        }
                        break;

                    case 'nl2br':
                        $p = 'nl2br(' . $p . ')';
                        break;

                    case 'default':
                        $s[1] = $s[1]{0} == '$' ?  $this->get_val(substr($s[1], 1)) : "'$s[1]'";
                        $p = '(' . $p . ' == \'\') ? ' . $s[1] . ' : ' . $p;
                        break;

                    case 'truncate':
                        $p = 'sub_str(' . $p . ",$s[1])";
                        break;

                    case 'strip_tags':
                        $p = 'strip_tags(' . $p . ')';
                        break;

                    case 'price':
                        $p = 'price_format(' . $p . ')';
                        break;

                    case 'date':
                        $p = 'date("' . $s[1] . '",' . $p . ')';
                        break;
                    case 'modifier':
                        if (function_exists($s[1]))
                        {
                            $p = 'call_user_func("' . $s[1] . '",' . $p . ')';
                        }

                        break;
                    default:
                        # code...
                        break;
                }
            }
        }

        return $p;
    }
    /**
     * 处理if标签
     *
     * @access  public
     * @param   string     $tag_args
     * @param   bool       $elseif
     *
     * @return  string
     */
    function _compile_if_tag($tag_args, $elseif = false)
    {
        preg_match_all('/\-?\d+[\.\d]+|\'[^\'|\s]*\'|"[^"|\s]*"|[\$\w\.]+|!==|===|==|!=|<>|<<|>>|<=|>=|&&|\|\||\(|\)|,|\!|\^|=|&|<|>|~|\||\%|\+|\-|\/|\*|\@|\S/', $tag_args, $match);

        $tokens = $match[0];

        for ($i = 0, $count = count($tokens); $i < $count; $i++)
        {
            $token = &$tokens[$i];
            if ($token[0] == '$')
            {
                $token = $this->get_val(substr($token, 1));
            }
        }

        if ($elseif)
        {
            return '<?php elseif (' . implode(' ', $tokens) . '): ?>';
        }
        else
        {
            return '<?php if (' . implode(' ', $tokens) . '): ?>';
        }
    }
    /**
     * 处理foreach标签
     *
     * @access  public
     * @param   string     $tag_args
     *
     * @return  string
     */
    function _compileforeach_start($tag_args)
    {
        $attr = $this->get_para($tag_args, 0);
        $from = $attr['from'];

        $item = $this->get_val($attr['item']);

        if (!empty($attr['key']))
        {
            $key = $attr['key'];
            $key_part = $this->get_val($key).' => ';
        }
        else
        {
            $attr['key'] = "";
            $key = null;
            $key_part = '';
        }

        if (!empty($attr['name']))
        {
            $name = $attr['name'];
        }
        else
        {
            $name = null;
        }

        $output = '<?php ';
        $output .= "\$_from = {$from}; if (!is_array(\$_from) && !is_object(\$_from)) { settype(\$_from, 'array'); }; \$this->pushvars('{$attr['key']}', '{$attr['item']}');";

        if (!empty($name))
        {
            $foreach_props = "\$this->foreach['$name']";
            $output .= "{$foreach_props} = array('total' => count(\$_from), 'iteration' => 0);\n";
            $output .= "if ({$foreach_props}['total'] > 0):\n";
            $output .= "    foreach (\$_from AS $key_part$item):\n";
            $output .= "        {$foreach_props}['iteration']++;\n";
        }
        else
        {
            $output .= "if (count(\$_from)):\n";
            $output .= "    foreach (\$_from AS $key_part$item):\n";
        }

        return $output . '?>';
    }
    /**
     * 将 foreach 的 key, item 放入临时数组
     *
     * @param  mixed    $key
     * @param  mixed    $val
     *
     * @return  void
     */
    function pushvars($key, $val = null)
    {
        if (!empty($key))
        {
            array_push($this->tempKey, "\$this->vars['$key']='" .$this->vars[$key] . "';");
        }
        if (!empty($val))
        {
            array_push($this->tempVal, "\$this->vars['$val']='" .$this->vars[$val] ."';");
        }
    }

    /**
     * 弹出临时数组的最后一个
     *
     * @return  void
     */
    function popvars()
    {
        $key = array_pop($this->tempKey);
        array_pop($this->tempVal);

        if (!empty($key))
        {
            eval($key);
        }
    }

    /**
     * 处理insert外部函数/需要include运行的函数的调用数据
     *
     * @access  public
     * @param   string     $val
     * @param   int         $type
     *
     * @return  array
     */
    function get_para($val, $type = 1) // 处理insert外部函数/需要include运行的函数的调用数据
    {
        $pa = $this->str_trim($val);
        foreach ($pa AS $value)
        {
            if (strrpos($value, '='))
            {
                list($a, $b) = explode('=', str_replace(array(' ', '"', "'", '&quot;'), '', $value));
                if ($b{0} == '$')
                {
                    if ($type)
                    {
                        eval('$para[\'' . $a . '\']=' . $this->get_val(substr($b, 1)) . ';');
                    }
                    else
                    {
                        $para[$a] = $this->get_val(substr($b, 1));
                    }
                }
                else
                {
                    $para[$a] = $b;
                }
            }
        }

        return $para;
    }

    /**
     * 处理模板文件
     * @param $filename
     * @return string|void
     */
    function fetch($filename)
    {
        if(empty($filename)){
            return "";
        }
        $filename = $this->template . '/' . $filename;
        if(is_file($filename)){
            return $this->_eval($this->fetch_str(file_get_contents($filename)));
        }
        else{
            return ;
        }
    }

    /**
     * 处理去掉$的字符串
     *
     * @access  public
     * @param   string     $val
     *
     * @return  bool
     */
    function makevar($val)
    {
        if (strrpos($val, '.') === false)
        {
            $p = '$this->var[\'' . $val . '\']';
        }
        else
        {
            $t = explode('.', $val);
            $var_name = array_shift($t);
            if ($var_name == 'smarty')
            {
                $p = $this->_compile_smarty_ref($t);
            }
            else
            {
                $p = '$this->var[\'' . $var_name . '\']';
            }
            foreach ($t AS &$val)
            {
                $p.= '[\'' . $val . '\']';
            }
        }

        return $p;
    }

    /**
     * 处理smarty开头的预定义变量
     *
     * @access  public
     * @param   array   $indexes
     *
     * @return  string
     */
    function _compile_smarty_ref(&$indexes)
    {
        /* Extract the reference name. */
        $_ref = $indexes[0];
        switch ($_ref)
        {
            case 'now':
                $compiled_ref = 'time()';
                break;

            case 'foreach':
                array_shift($indexes);
                $var = $indexes[0];
                $_propname = $indexes[1];
                switch ($_propname)
                {
                    case 'index':
                        array_shift($indexes);
                        $compiled_ref = "(\$this->foreach['$var']['iteration'] - 1)";
                        break;

                    case 'first':
                        array_shift($indexes);
                        $compiled_ref = "(\$this->foreach['$var']['iteration'] <= 1)";
                        break;

                    case 'last':
                        array_shift($indexes);
                        $compiled_ref = "(\$this->foreach['$var']['iteration'] == \$this->foreach['$var']['total'])";
                        break;

                    case 'show':
                        array_shift($indexes);
                        $compiled_ref = "(\$this->foreach['$var']['total'] > 0)";
                        break;

                    default:
                        $compiled_ref = "\$this->foreach['$var']";
                        break;
                }
                break;

            case 'get':
                $compiled_ref = '$_GET';
                break;

            case 'post':
                $compiled_ref = '$_POST';
                break;

            case 'cookies':
                $compiled_ref = '$_COOKIE';
                break;

            case 'env':
                $compiled_ref = '$_ENV';
                break;

            case 'server':
                $compiled_ref = '$_SERVER';
                break;

            case 'request':
                $compiled_ref = '$_REQUEST';
                break;

            case 'session':
                $compiled_ref = '$_SESSION';
                break;

            case 'const':
                array_shift($indexes);
                $compiled_ref = '@constant("' . strtoupper($indexes[0]) . '")';
                break;

            default:
                // $this->_syntax_error('$smarty.' . $_ref . ' is an unknown reference', E_USER_ERROR, __FILE__, __LINE__);
                break;
        }
        array_shift($indexes);

        return $compiled_ref;
    }
    function str_trim($str)
    {
        /* 处理'a=b c=d k = f '类字符串，返回数组 */
        while (strpos($str, '= ') != 0)
        {
            $str = str_replace('= ', '=', $str);
        }
        while (strpos($str, ' =') != 0)
        {
            $str = str_replace(' =', '=', $str);
        }

        return explode(' ', trim($str));
    }
    function _eval(&$content)
    {
        ob_start();
        eval('?' . '>' . trim($content));

        $content = ob_get_contents();
        ob_end_clean();

        return $content;
    }


    /**
     * 设置视图路径，接口的抽象方法，必须实现的
     *
     * @param $templateDir
     * @return $this
     */
    public function setScriptPath($templateDir) {
        if (is_string($templateDir) )
        {
            $this->template = $templateDir;
        }

        return $this;
    }

    /**
     * 获取视图路径，接口的抽象方法，必须实现的
     * @return mixed
     */
    public function getScriptPath() {
        return $this->template;
    }
}

?>
