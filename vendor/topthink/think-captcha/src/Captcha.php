<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2015 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think\captcha;

use think\Session;

class Captcha
{
    protected $config = [
        'seKey'    => 'ThinkPHP.CN',
        // CAPTCHA hashing key
        'codeSet'  => '2345678abcdefhijkmnpqrstuvwxyzABCDEFGHJKLMNPQRTUVWXY',
        // CAPTCHA character set
        'expire'   => 1800,
        // CAPTCHA expiration time in seconds
        'useZh'    => false,
        // Use Chinese CAPTCHA characters
        'zhSet'    => '们以我到他会作时要动国产的一是工就年阶义发成部民可出能方进在了不和有大这主中人上为来分生对于学下级地个用同行面说种过命度革而多子后自社加小机也经力线本电高量长党得实家定深法表着水理化争现所二起政三好十战无农使性前等反体合斗路图把结第里正新开论之物从当两些还天资事队批点育重其思与间内去因件日利相由压员气业代全组数果期导平各基或月毛然如应形想制心样干都向变关问比展那它最及外没看治提五解系林者米群头意只明四道马认次文通但条较克又公孔领军流入接席位情运器并飞原油放立题质指建区验活众很教决特此常石强极土少已根共直团统式转别造切九你取西持总料连任志观调七么山程百报更见必真保热委手改管处己将修支识病象几先老光专什六型具示复安带每东增则完风回南广劳轮科北打积车计给节做务被整联步类集号列温装即毫知轴研单色坚据速防史拉世设达尔场织历花受求传口断况采精金界品判参层止边清至万确究书术状厂须离再目海交权且儿青才证低越际八试规斯近注办布门铁需走议县兵固除般引齿千胜细影济白格效置推空配刀叶率述今选养德话查差半敌始片施响收华觉备名红续均药标记难存测士身紧液派准斤角降维板许破述技消底床田势端感往神便贺村构照容非搞亚磨族火段算适讲按值美态黄易彪服早班麦削信排台声该击素张密害侯草何树肥继右属市严径螺检左页抗苏显苦英快称坏移约巴材省黑武培著河帝仅针怎植京助升王眼她抓含苗副杂普谈围食射源例致酸旧却充足短划剂宣环落首尺波承粉践府鱼随考刻靠够满夫失包住促枝局菌杆周护岩师举曲春元超负砂封换太模贫减阳扬江析亩木言球朝医校古呢稻宋听唯输滑站另卫字鼓刚写刘微略范供阿块某功套友限项余倒卷创律雨让骨远帮初皮播优占死毒圈伟季训控激找叫云互跟裂粮粒母练塞钢顶策双留误础吸阻故寸盾晚丝女散焊功株亲院冷彻弹错散商视艺灭版烈零室轻血倍缺厘泵察绝富城冲喷壤简否柱李望盘磁雄似困巩益洲脱投送奴侧润盖挥距触星松送获兴独官混纪依未突架宽冬章湿偏纹吃执阀矿寨责熟稳夺硬价努翻奇甲预职评读背协损棉侵灰虽矛厚罗泥辟告卵箱掌氧恩爱停曾溶营终纲孟钱待尽俄缩沙退陈讨奋械载胞幼哪剥迫旋征槽倒握担仍呀鲜吧卡粗介钻逐弱脚怕盐末阴丰雾冠丙街莱贝辐肠付吉渗瑞惊顿挤秒悬姆烂森糖圣凹陶词迟蚕亿矩康遵牧遭幅园腔订香肉弟屋敏恢忘编印蜂急拿扩伤飞露核缘游振操央伍域甚迅辉异序免纸夜乡久隶缸夹念兰映沟乙吗儒杀汽磷艰晶插埃燃欢铁补咱芽永瓦倾阵碳演威附牙芽永瓦斜灌欧献顺猪洋腐请透司危括脉宜笑若尾束壮暴企菜穗楚汉愈绿拖牛份染既秋遍锻玉夏疗尖殖井费州访吹荣铜沿替滚客召旱悟刺脑措贯藏敢令隙炉壳硫煤迎铸粘探临薄旬善福纵择礼愿伏残雷延烟句纯渐耕跑泽慢栽鲁赤繁境潮横掉锥希池败船假亮谓托伙哲怀割摆贡呈劲财仪沉炼麻罪祖息车穿货销齐鼠抽画饲龙库守筑房歌寒喜哥洗蚀废纳腹乎录镜妇恶脂庄擦险赞钟摇典柄辩竹谷卖乱虚桥奥伯赶垂途额壁网截野遗静谋弄挂课镇妄盛耐援扎虑键归符庆聚绕摩忙舞遇索顾胶羊湖钉仁音迹碎伸灯避泛亡答勇频皇柳哈揭甘诺概宪浓岛袭谁洪谢炮浇斑讯懂灵蛋闭孩释乳巨徒私银伊景坦累匀霉杜乐勒隔弯绩招绍胡呼痛峰零柴簧午跳居尚丁秦稍追梁折耗碱殊岗挖氏刃剧堆赫荷胸衡勤膜篇登驻案刊秧缓凸役剪川雪链渔啦脸户洛孢勃盟买杨宗焦赛旗滤硅炭股坐蒸凝竟陷枪黎救冒暗洞犯筒您宋弧爆谬涂味津臂障褐陆啊健尊豆拔莫抵桑坡缝警挑污冰柬嘴啥饭塑寄赵喊垫丹渡耳刨虎笔稀昆浪萨茶滴浅拥穴覆伦娘吨浸袖珠雌妈紫戏塔锤震岁貌洁剖牢锋疑霸闪埔猛诉刷狠忽灾闹乔唐漏闻沈熔氯荒茎男凡抢像浆旁玻亦忠唱蒙予纷捕锁尤乘乌智淡允叛畜俘摸锈扫毕璃宝芯爷鉴秘净蒋钙肩腾枯抛轨堂拌爸循诱祝励肯酒绳穷塘燥泡袋朗喂铝软渠颗惯贸粪综墙趋彼届墨碍启逆卸航衣孙龄岭骗休借',
        // Chinese CAPTCHA character set
        'useImgBg' => false,
        // Use a background image
        'fontSize' => 25,
        // CAPTCHA font size in pixels
        'useCurve' => true,
        // Whether to draw an interference curve
        'useNoise' => true,
        // Whether to add noise
        'imageH'   => 0,
        // CAPTCHA image height
        'imageW'   => 0,
        // CAPTCHA image width
        'length'   => 5,
        // Number of CAPTCHA characters
        'fontttf'  => '',
        // CAPTCHA font file; chosen randomly when not configured
        'bg'       => [243, 251, 254],
        // background color
        'reset'    => true,
        // Whether to reset after successful verification
    ];

    private $_image = null; // CAPTCHA image resource
    private $_color = null; // CAPTCHA text color

    /* *
 * Initialize the CAPTCHA configuration
 * @access public
 * @param array $config configuration parameters */
    public function __construct($config = [])
    {
        $this->config = array_merge($this->config, $config);
    }

    /* *
 * Use $this->name to get the configuration
 * @access public
 * @param string $name configuration name
 * @return mixed configuration values */
    public function __get($name)
    {
        return $this->config[$name];
    }

    /* *
 * Set a CAPTCHA configuration value
 * @access public
 * @param string $name configuration name
 * @param string $value configuration value
 * @return void */
    public function __set($name, $value)
    {
        if (isset($this->config[$name])) {
            $this->config[$name] = $value;
        }
    }

    /* *
 * Check whether a configuration value exists
 * @access public
 * @param string $name configuration name
 * @return bool */
    public function __isset($name)
    {
        return isset($this->config[$name]);
    }

    /* *
 * Check the submitted CAPTCHA code
 * @access public
 * @param string $code Submitted CAPTCHA code
 * @param string $id Captcha ID
 * @return bool Whether the submitted CAPTCHA code matches */
    public function check($code, $id = '')
    {
        $key = $this->authcode($this->seKey) . $id;
        // The submitted code and stored CAPTCHA data must both be present
        $secode = Session::get($key, '');
        if (empty($code) || empty($secode)) {
            return false;
        }
        // The stored CAPTCHA has expired
        if (time() - $secode['verify_time'] > $this->expire) {
            Session::delete($key, '');
            return false;
        }

        if ($this->authcode(strtoupper($code)) == $secode['verify_code']) {
            $this->reset && Session::delete($key, '');
            return true;
        }

        return false;
    }

    /* *
 * Generate a CAPTCHA image and store its hashed code in the session
 * Session data format: ['verify_code' => 'hashed CAPTCHA code', 'verify_time' => 'creation timestamp']
 * @access public
 * @param string $id CAPTCHA identifier
 * @return \think\Response */
    public function entry($id = '')
    {
        // Image width (px)
        $this->imageW || $this->imageW = $this->length * $this->fontSize * 1.5 + $this->length * $this->fontSize / 2;
        // Image height (px)
        $this->imageH || $this->imageH = $this->fontSize * 2.5;
        // Create an image of $this->imageW x $this->imageH
        $this->_image = imagecreate((int) $this->imageW, (int) $this->imageH);
        // Set the background
        imagecolorallocate($this->_image, $this->bg[0], $this->bg[1], $this->bg[2]);

        // Choose a random CAPTCHA text color
        $this->_color = imagecolorallocate($this->_image, mt_rand(1, 150), mt_rand(1, 150), mt_rand(1, 150));
        // Use random font for captcha
        $ttfPath = __DIR__ . '/../assets/' . ($this->useZh ? 'zhttfs' : 'ttfs') . '/';

        if (empty($this->fontttf)) {
            $dir  = dir($ttfPath);
            $ttfs = [];
            while (false !== ($file = $dir->read())) {
                if ('.' != $file[0] && substr($file, -4) == '.ttf') {
                    $ttfs[] = $file;
                }
            }
            $dir->close();
            $this->fontttf = $ttfs[array_rand($ttfs)];
        }
        $fontttf = $ttfPath . $this->fontttf;

        if ($this->useImgBg) {
            $this->_background();
        }

        if ($this->useNoise) {
            // Draw character noise
            $this->_writeNoise();
        }
        if ($this->useCurve) {
            // Draw an interference curve
            $this->_writeCurve();
        }

        // Draw CAPTCHA characters
        $code   = []; // Verification code
        $codeNX = 0; // Horizontal position of the next CAPTCHA character
        if ($this->useZh) {
            // Chinese CAPTCHA characters
            for ($i = 0; $i < $this->length; $i++) {
                $code[$i] = iconv_substr($this->zhSet, mt_rand(0, mb_strlen($this->zhSet, 'utf-8') - 1), 1, 'utf-8');
                imagettftext($this->_image, $this->fontSize, mt_rand(-40, 40), (int) ($this->fontSize * ($i + 1) * 1.5), (int) ($this->fontSize + mt_rand(10, 20)), $this->_color, $fontttf, $code[$i]);
            }
        } else {
            for ($i = 0; $i < $this->length; $i++) {
                $code[$i] = $this->codeSet[mt_rand(0, strlen($this->codeSet) - 1)];
                $codeNX += mt_rand((int) ($this->fontSize * 1.2), (int) ($this->fontSize * 1.6));
                imagettftext($this->_image, $this->fontSize, mt_rand(-40, 40), $codeNX, (int) ($this->fontSize * 1.6), $this->_color, $fontttf, $code[$i]);
            }
        }

        // Store the hashed CAPTCHA code
        $key                   = $this->authcode($this->seKey);
        $code                  = $this->authcode(strtoupper(implode('', $code)));
        $secode                = [];
        $secode['verify_code'] = $code; // Store the hashed CAPTCHA code in the session
        $secode['verify_time'] = time(); // CAPTCHA creation timestamp
        Session::set($key . $id, $secode, '');

        ob_start();
        // Output the image
        imagepng($this->_image);
        $content = ob_get_clean();
        if (is_resource($this->_image)) {
            imagedestroy($this->_image);
        }

        return response($content, 200, ['Content-Length' => strlen($content)])->contentType('image/png');
    }

    /* *
 * Draw an interference curve by connecting two randomly generated sine-wave segments
 *
 * Sine-wave parameters used by the curve calculation:
 * Curve formula: y = A * sin(ωx + φ) + b + imageH / 2
 * The parameters control the shape and position of the curve:
 * A: Amplitude, which controls the height of the wave
 * b: Vertical offset relative to the image center
 * φ: Phase offset, which shifts the wave horizontally
 * ω: Angular frequency, with period T = 2π/ω
 * */
    private function _writeCurve()
    {
        $px = $py = 0;

        // First curve segment
        $A = mt_rand(1, (int) ($this->imageH / 2)); // amplitude
        $b = mt_rand((int) (-$this->imageH / 4), (int) ($this->imageH / 4)); // Vertical offset
        $f = mt_rand((int) (-$this->imageH / 4), (int) ($this->imageH / 4)); // Phase offset
        $T = mt_rand((int) $this->imageH, (int) ($this->imageW * 2)); // Period
        $w = (2 * M_PI) / $T;

        $px1 = 0; // Starting horizontal position of this curve segment
        $px2 = mt_rand((int) ($this->imageW / 2), (int) ($this->imageW * 0.8)); // Ending horizontal position of this curve segment

        for ($px = $px1; $px <= $px2; $px = $px + 1) {
            if (0 != $w) {
                $py = $A * sin($w * $px + $f) + $b + $this->imageH / 2; // y = A * sin(ωx + φ) + b + imageH / 2
                $i  = (int)($this->fontSize / 5);
                while ($i > 0) {
                    imagesetpixel($this->_image, (int) ($px + $i), (int) ($py + $i), $this->_color); // Draw a short pixel stroke to give the curve thickness
                    $i--;
                }
            }
        }

        // Second curve segment
        $A   = mt_rand(1, (int) ($this->imageH / 2)); // amplitude
        $f   = mt_rand((int) (-$this->imageH / 4), (int) ($this->imageH / 4)); // Phase offset
        $T   = mt_rand((int) $this->imageH, (int) ($this->imageW * 2)); // Period
        $w   = (2 * M_PI) / $T;
        $b   = $py - $A * sin($w * $px + $f) - $this->imageH / 2;
        $px1 = $px2;
        $px2 = $this->imageW;

        for ($px = $px1; $px <= $px2; $px = $px + 1) {
            if (0 != $w) {
                $py = $A * sin($w * $px + $f) + $b + $this->imageH / 2; // y = A * sin(ωx + φ) + b + imageH / 2
                $i  = (int)($this->fontSize / 5);
                while ($i > 0) {
                    imagesetpixel($this->_image, (int) ($px + $i), (int) ($py + $i), $this->_color);
                    $i--;
                }
            }
        }
    }

    /* *
 * Draw character noise
 * Draw letters and numbers in varying colors over the image */
    private function _writeNoise()
    {
        $codeSet = '2345678abcdefhijkmnpqrstuvwxyz';
        for ($i = 0; $i < 10; $i++) {
            // Color of specks
            $noiseColor = imagecolorallocate($this->_image, mt_rand(150, 225), mt_rand(150, 225), mt_rand(150, 225));
            for ($j = 0; $j < 5; $j++) {
                // Draw character noise
                imagestring($this->_image, 5, mt_rand(-10, (int) $this->imageW), mt_rand(-10, (int) $this->imageH), $codeSet[mt_rand(0, 29)], $noiseColor);
            }
        }
    }

    /* *
 * Draw a background image
 * Note: If the Output the image of the verification code is large, it will take up more system resources */
    private function _background()
    {
        $path = dirname(__FILE__) . '/verify/bgs/';
        $dir  = dir($path);

        $bgs = [];
        while (false !== ($file = $dir->read())) {
            if ('.' != $file[0] && substr($file, -4) == '.jpg') {
                $bgs[] = $path . $file;
            }
        }
        $dir->close();

        $gb = $bgs[array_rand($bgs)];

        list($width, $height) = @getimagesize($gb);
        // Resample
        $bgImage = @imagecreatefromjpeg($gb);
        @imagecopyresampled($this->_image, $bgImage, 0, 0, 0, 0, (int) $this->imageW, (int) $this->imageH, $width, $height);
        if (is_resource($bgImage)) {
            @imagedestroy($bgImage);
        }
    }

    /* Hash a CAPTCHA value */
    private function authcode($str)
    {
        $key = substr(md5($this->seKey), 5, 8);
        $str = substr(md5($str), 8, 10);
        return md5($key . $str);
    }
}