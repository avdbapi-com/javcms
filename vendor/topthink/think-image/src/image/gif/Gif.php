<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2015 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think\image\gif;

class Gif
{
    /* *
 * GIF frame list
 *
 * @var array */
    private $frames = [];
    /* *
 * Delay for each GIF frame
 *
 * @var array */
    private $delays = [];

    /* *
 * Initialize the GIF frames from a file or binary data
 *
 * @param string $src GIF file path or binary data
 * @param string $mod Source mode: url for a file path or bin for binary data
 * @throws \\Exception */
    public function __construct($src = null, $mod = 'url')
    {
        if (!is_null($src)) {
            if ('url' == $mod && is_file($src)) {
                $src = file_get_contents($src);
            }
            /* Decode the GIF frames */
            try {
                $de           = new Decoder($src);
                $this->frames = $de->getFrames();
                $this->delays = $de->getDelays();
            } catch (\Exception $e) {
                throw new \Exception("Failed to decode the GIF image");
            }
        }
    }

    /* *
 * Set or get the data of the current frame
 *
 * @param string $stream Binary data stream
 * @return mixed Current frame data when reading */
    public function image($stream = null)
    {
        if (is_null($stream)) {
            $current = current($this->frames);
            return false === $current ? reset($this->frames) : $current;
        }
        $this->frames[key($this->frames)] = $stream;
    }

    /* *
 * Advance to the next GIF frame
 *
 * @return string Current frame data */
    public function nextImage()
    {
        return next($this->frames);
    }

    /* *
 * Encode and save the GIF frames
 *
 * @param string $pathname Output GIF filename */
    public function save($pathname)
    {
        $gif = new Encoder($this->frames, $this->delays, 0, 2, 0, 0, 0, 'bin');
        file_put_contents($pathname, $gif->getAnimation());
    }
}