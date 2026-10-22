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

namespace think;

use think\image\Exception as ImageException;
use think\image\gif\Gif;

class Image
{

    /* Thumbnail-related constant definitions */
    const THUMB_SCALING   = 1; // Scale the thumbnail proportionally
    const THUMB_FILLED    = 2; // Scale the thumbnail proportionally and pad to the target size
    const THUMB_CENTER    = 3; // Crop the thumbnail from the center
    const THUMB_NORTHWEST = 4; // Crop the thumbnail from the upper-left corner
    const THUMB_SOUTHEAST = 5; // Crop the thumbnail from the lower-right corner
    const THUMB_FIXED     = 6; // Scale the thumbnail to a fixed size
    /* Watermark-related constant definitions */
    const WATER_NORTHWEST = 1; // Constant that marks the watermark in the upper left corner
    const WATER_NORTH     = 2; // Position the watermark at the top center
    const WATER_NORTHEAST = 3; // Constant that identifies the watermark in the upper right corner
    const WATER_WEST      = 4; // Constant indicating a left-centered watermark
    const WATER_CENTER    = 5; // Constant, indicating a centered watermark
    const WATER_EAST      = 6; // Constant, indicating a right-centered watermark
    const WATER_SOUTHWEST = 7; // Constant that marks the watermark in the lower left corner
    const WATER_SOUTH     = 8; // Position the watermark at the bottom center
    const WATER_SOUTHEAST = 9; // Constant, identifying a watermark in the lower right corner
    /* Flipping related constant definitions */
    const FLIP_X = 1; // Flip across the X axis
    const FLIP_Y = 2; // Flip across the Y axis

    /* *
 * Image resource object
 *
 * @var resource */
    protected $im;

    /* * @var Gif */
    protected $gif;

    /* *
 * Image information, including width, height, type, mime, size
 *
 * @var array */
    protected $info;

    protected function __construct(\SplFileInfo $file)
    {
        // Get image information
        $info = @getimagesize($file->getPathname());

        // Validate the image information
        if (false === $info || (IMAGETYPE_GIF === $info[2] && empty($info['bits']))) {
            throw new ImageException('Invalid image file');
        }

        // Set image information
        $this->info = [
            'width'  => $info[0],
            'height' => $info[1],
            'type'   => image_type_to_extension($info[2], false),
            'mime'   => $info['mime'],
        ];

        // Open an image
        if ('gif' == $this->info['type']) {
            $this->gif = new Gif($file->getPathname());
            $this->im  = @imagecreatefromstring($this->gif->image());
        } else {
            $fun      = "imagecreatefrom{$this->info['type']}";
            if(!function_exists($fun)){
                return false;
            }
            $this->im = @$fun($file->getPathname());
        }

        if (empty($this->im)) {
            throw new ImageException('Failed to create the image resource');
        }

    }

    /* *
 * Open an image file
 * @param \SplFileInfo|string $file Image file
 * @return Image */
    public static function open($file)
    {
        if (is_string($file)) {
            $file = new \SplFileInfo($file);
        }
        if (!$file->isFile()) {
            throw new ImageException('Image file does not exist');
        }
        return new self($file);
    }

    /* *
 * Save image
 * @param string $pathname Path name to save the image
 * @param null|string $type Image format
 * @param int $quality image quality
 * @param bool $interlace Whether to set interlacing for JPEG type images
 * @return $this */
    public function save($pathname, $type = null, $quality = 80, $interlace = true)
    {
        // Get the image type automatically
        if (is_null($type)) {
            $type = $this->info['type'];
        } else {
            $type = strtolower($type);
        }
        // Save image
        if ('jpeg' == $type || 'jpg' == $type) {
            // Set progressive encoding for JPEG images
            imageinterlace($this->im, $interlace);
            imagejpeg($this->im, $pathname, $quality);
        } elseif ('gif' == $type && !empty($this->gif)) {
            $this->gif->save($pathname);
        } elseif ('png' == $type) {
            // Set to save full alpha channel information
            imagesavealpha($this->im, true);
            // PNG compression levels range from 0 to 9
            imagepng($this->im, $pathname, min((int) ($quality / 10), 9));
        } else {
            $fun = 'image' . $type;
            if(!function_exists($fun)){
                return false;
            }
            $fun($this->im, $pathname);
        }

        return $this;
    }

    /* *
 * Returns the width of the image
 * @return int image width */
    public function width()
    {
        return $this->info['width'];
    }

    /* *
 * Returns the height of the image
 * @return int image height */
    public function height()
    {
        return $this->info['height'];
    }

    /* *
 * Returns the type of image
 * @return string image type */
    public function type()
    {
        return $this->info['type'];
    }

    /* *
 * Returns the MIME type of the image
 * @return string The MIME type of the image */
    public function mime()
    {
        return $this->info['mime'];
    }

    /* *
 * Returns an array of image dimensions 0 - image width, 1 - image height
 * @return array image size */
    public function size()
    {
        return [$this->info['width'], $this->info['height']];
    }

    /* *
 * Rotate the image
 * @param int $degrees the number of degrees rotated clockwise
 * @return $this */
    public function rotate($degrees = 90)
    {
        do {
            $img = imagerotate($this->im, -$degrees, imagecolorallocatealpha($this->im, 0, 0, 0, 127));
            is_resource($this->im) && imagedestroy($this->im);
            $this->im = $img;
        } while (!empty($this->gif) && $this->gifNext());

        $this->info['width']  = imagesx($this->im);
        $this->info['height'] = imagesy($this->im);

        return $this;
    }

    /* *
 * Flipping an image
 * @param integer $direction Flip direction: FLIP_X or FLIP_Y
 * @return $this */
    public function flip($direction = self::FLIP_X)
    {
        // Original image width and height
        $w = $this->info['width'];
        $h = $this->info['height'];

        do {

            $img = imagecreatetruecolor($w, $h);

            switch ($direction) {
                case self::FLIP_X:
                    for ($y = 0; $y < $h; $y++) {
                        imagecopy($img, $this->im, 0, $h - $y - 1, 0, $y, $w, 1);
                    }
                    break;
                case self::FLIP_Y:
                    for ($x = 0; $x < $w; $x++) {
                        imagecopy($img, $this->im, $w - $x - 1, 0, $x, 0, 1, $h);
                    }
                    break;
                default:
                    throw new ImageException('Unsupported flip type');
            }

            is_resource($this->im) && imagedestroy($this->im);
            $this->im = $img;

        } while (!empty($this->gif) && $this->gifNext());

        return $this;
    }

    /* *
 * Crop images
 *
 * @param integer $w width of the cropping area
 * @param integer $h height of cropping area
 * @param integer $x the x coordinate of the cropping area
 * @param integer $y y coordinate of the cropping area
 * @param integer $width Output image width
 * @param integer $height Output image height
 *
 * @return $this */
    public function crop($w, $h, $x = 0, $y = 0, $width = null, $height = null)
    {
        // Set the output dimensions
        empty($width) && $width   = $w;
        empty($height) && $height = $h;
        do {
            // Create a new image
            $img = imagecreatetruecolor((int) $width, (int) $height);
            // Fill the new image with a white background
            $color = imagecolorallocate($img, 255, 255, 255);
            imagefill($img, 0, 0, $color);
            // Resample the selected image region
            imagecopyresampled($img, $this->im, 0, 0, (int) $x, (int) $y, (int) $width, (int) $height, (int) $w, (int) $h);
            is_resource($this->im) && imagedestroy($this->im); // Destroy the original image
            // Use the new image resource
            $this->im = $img;
        } while (!empty($this->gif) && $this->gifNext());
        $this->info['width']  = (int) $width;
        $this->info['height'] = (int) $height;
        return $this;
    }

    /* *
 * Generate thumbnails
 *
 * @param integer $width The maximum width of the thumbnail
 * @param integer $height The maximum height of the thumbnail
 * @param int $type Thumbnail crop type
 *
 * @return $this */
    public function thumb($width, $height, $type = self::THUMB_SCALING)
    {
        // Original image width and height
        $w = $this->info['width'];
        $h = $this->info['height'];
        /* Compute the necessary parameters for thumbnail generation */
        switch ($type) {
            /* Proportional scaling */
            case self::THUMB_SCALING:
                // Keep the original image when both dimensions are smaller than the target
                if ($w < $width && $h < $height) {
                    return $this;
                }
                // Calculate the scaling ratio
                $scale = min($width / $w, $height / $h);
                // Set the thumbnail\'s coordinates, width, and height
                $x      = $y      = 0;
                $width  = $w * $scale;
                $height = $h * $scale;
                break;
            /* Centered crop */
            case self::THUMB_CENTER:
                // Calculate the scaling ratio
                $scale = max($width / $w, $height / $h);
                // Set the thumbnail\'s coordinates, width, and height
                $w = $width / $scale;
                $h = $height / $scale;
                $x = ($this->info['width'] - $w) / 2;
                $y = ($this->info['height'] - $h) / 2;
                break;
            /* Top left corner cropped */
            case self::THUMB_NORTHWEST:
                // Calculate the scaling ratio
                $scale = max($width / $w, $height / $h);
                // Set the thumbnail\'s coordinates, width, and height
                $x = $y = 0;
                $w = $width / $scale;
                $h = $height / $scale;
                break;
            /* Lower right corner cropping */
            case self::THUMB_SOUTHEAST:
                // Calculate the scaling ratio
                $scale = max($width / $w, $height / $h);
                // Set the thumbnail\'s coordinates, width, and height
                $w = $width / $scale;
                $h = $height / $scale;
                $x = $this->info['width'] - $w;
                $y = $this->info['height'] - $h;
                break;
            /* Proportional scaling with padding */
            case self::THUMB_FILLED:
                // Calculate the scaling ratio
                if ($w < $width && $h < $height) {
                    $scale = 1;
                } else {
                    $scale = min($width / $w, $height / $h);
                }
                // Set the thumbnail\'s coordinates, width, and height
                $neww = $w * $scale;
                $newh = $h * $scale;
                $x    = $this->info['width'] - $w;
                $y    = $this->info['height'] - $h;
                $posx = ($width - $w * $scale) / 2;
                $posy = ($height - $h * $scale) / 2;
                do {
                    // Create a new image
                    $img = imagecreatetruecolor((int) $width, (int) $height);
                    // Fill the new image with a white background
                    $color = imagecolorallocate($img, 255, 255, 255);
                    imagefill($img, 0, 0, $color);
                    // Resample the selected image region
                    imagecopyresampled($img, $this->im, (int) $posx, (int) $posy, (int) $x, (int) $y, (int) $neww, (int) $newh, (int) $w, (int) $h);
                    is_resource($this->im) && imagedestroy($this->im); // Destroy the original image
                    $this->im = $img;
                } while (!empty($this->gif) && $this->gifNext());
                $this->info['width']  = (int) $width;
                $this->info['height'] = (int) $height;
                return $this;
            /* Fixed-size scaling */
            case self::THUMB_FIXED:
                $x = $y = 0;
                break;
            default:
                throw new ImageException('Unsupported thumbnail crop type');
        }
        /* Crop an image */
        return $this->crop($w, $h, $x, $y, $width, $height);
    }

    /* *
 * Add watermarks
 *
 * @param string $source Watermark image path
 * @param int $locate watermark position
 * @param int $alpha transparency
 * @return $this */
    public function water($source, $locate = self::WATER_SOUTHEAST, $alpha = 100)
    {
        if (!is_file($source)) {
            throw new ImageException('Watermark image does not exist');
        }
        // Get watermark image information
        $info = getimagesize($source);
        if (false === $info || (IMAGETYPE_GIF === $info[2] && empty($info['bits']))) {
            throw new ImageException('Invalid watermark file');
        }
        // Create the watermark image resource
        $fun   = 'imagecreatefrom' . image_type_to_extension($info[2], false);
        if(!function_exists($fun)){
            return false;
        }
        $water = $fun($source);
        // Set the color blending mode for the watermark image
        imagealphablending($water, true);
        /* Set the watermark position */
        switch ($locate) {
            /* Watermark in the lower right corner */
            case self::WATER_SOUTHEAST:
                $x = $this->info['width'] - $info[0];
                $y = $this->info['height'] - $info[1];
                break;
            /* Lower left corner watermark */
            case self::WATER_SOUTHWEST:
                $x = 0;
                $y = $this->info['height'] - $info[1];
                break;
            /* Watermark in the upper left corner */
            case self::WATER_NORTHWEST:
                $x = $y = 0;
                break;
            /* Watermark in the upper right corner */
            case self::WATER_NORTHEAST:
                $x = $this->info['width'] - $info[0];
                $y = 0;
                break;
            /* centered watermark */
            case self::WATER_CENTER:
                $x = ($this->info['width'] - $info[0]) / 2;
                $y = ($this->info['height'] - $info[1]) / 2;
                break;
            /* Bottom-center watermark */
            case self::WATER_SOUTH:
                $x = ($this->info['width'] - $info[0]) / 2;
                $y = $this->info['height'] - $info[1];
                break;
            /* Right centered watermark */
            case self::WATER_EAST:
                $x = $this->info['width'] - $info[0];
                $y = ($this->info['height'] - $info[1]) / 2;
                break;
            /* Top center watermark */
            case self::WATER_NORTH:
                $x = ($this->info['width'] - $info[0]) / 2;
                $y = 0;
                break;
            /* Left center watermark */
            case self::WATER_WEST:
                $x = 0;
                $y = ($this->info['height'] - $info[1]) / 2;
                break;
            default:
                /* Custom watermark coordinates */
                if (is_array($locate)) {
                    list($x, $y) = $locate;
                } else {
                    throw new ImageException('Unsupported watermark position type');
                }
        }
        do {
            // Add watermarks
            $src = imagecreatetruecolor($info[0], $info[1]);
            // Fill the new image with a white background
            $color = imagecolorallocate($src, 255, 255, 255);
            imagefill($src, 0, 0, $color);
            imagecopy($src, $this->im, 0, 0, (int) $x, (int) $y, $info[0], $info[1]);
            imagecopy($src, $water, 0, 0, 0, 0, $info[0], $info[1]);
            imagecopymerge($this->im, $src, (int) $x, (int) $y, 0, 0, $info[0], $info[1], $alpha);
            // Destroy the temporary image resource
            is_resource($src) && imagedestroy($src);
        } while (!empty($this->gif) && $this->gifNext());
        // Destroy watermark resources
        is_resource($water) && imagedestroy($water);
        return $this;
    }

    /* *
 * Add text to images
 *
 * @param string $text The text added
 * @param string $font Font file path
 * @param integer $size Font size
 * @param string $color Text color
 * @param int $locate Text position
 * @param integer $offset The offset of the text from its current position
 * @param integer $angle The angle at which the text is tilted
 *
 * @return $this
 * @throws ImageException */
    public function text($text, $font, $size, $color = '#00000000',
        $locate = self::WATER_SOUTHEAST, $offset = 0, $angle = 0) {

        if (!is_file($font)) {
            throw new ImageException("Font file does not exist: {$font}");
        }
        // Get text information
        $info = imagettfbbox($size, $angle, $font, $text);
        $minx = min($info[0], $info[2], $info[4], $info[6]);
        $maxx = max($info[0], $info[2], $info[4], $info[6]);
        $miny = min($info[1], $info[3], $info[5], $info[7]);
        $maxy = max($info[1], $info[3], $info[5], $info[7]);
        /* Calculate the initial coordinates and dimensions of text */
        $x = $minx;
        $y = abs($miny);
        $w = $maxx - $minx;
        $h = $maxy - $miny;
        /* Set text position */
        switch ($locate) {
            /* bottom-right text */
            case self::WATER_SOUTHEAST:
                $x += $this->info['width'] - $w;
                $y += $this->info['height'] - $h;
                break;
            /* lower-left text */
            case self::WATER_SOUTHWEST:
                $y += $this->info['height'] - $h;
                break;
            /* upper-left text */
            case self::WATER_NORTHWEST:
                // The starting coordinate is the upper left corner coordinate, no adjustment is required
                break;
            /* Text in the upper right corner */
            case self::WATER_NORTHEAST:
                $x += $this->info['width'] - $w;
                break;
            /* Centered text */
            case self::WATER_CENTER:
                $x += ($this->info['width'] - $w) / 2;
                $y += ($this->info['height'] - $h) / 2;
                break;
            /* Bottom-center text */
            case self::WATER_SOUTH:
                $x += ($this->info['width'] - $w) / 2;
                $y += $this->info['height'] - $h;
                break;
            /* Right-center text */
            case self::WATER_EAST:
                $x += $this->info['width'] - $w;
                $y += ($this->info['height'] - $h) / 2;
                break;
            /* Top-center text */
            case self::WATER_NORTH:
                $x += ($this->info['width'] - $w) / 2;
                break;
            /* Left-center text */
            case self::WATER_WEST:
                $y += ($this->info['height'] - $h) / 2;
                break;
            default:
                /* Custom text coordinates */
                if (is_array($locate)) {
                    list($posx, $posy) = $locate;
                    $x += $posx;
                    $y += $posy;
                } else {
                    throw new ImageException('Unsupported text position type');
                }
        }
        /* Set the offset */
        if (is_array($offset)) {
            $offset        = array_map('intval', $offset);
            list($ox, $oy) = $offset;
        } else {
            $offset = intval($offset);
            $ox     = $oy     = $offset;
        }
        /* Set the color */
        if (is_string($color) && 0 === strpos($color, '#')) {
            $color = str_split(substr($color, 1), 2);
            $color = array_map('hexdec', $color);
            if (empty($color[3]) || $color[3] > 127) {
                $color[3] = 0;
            }
        } elseif (!is_array($color)) {
            throw new ImageException('Invalid color value');
        }
        do {
            /* Write text */
            $col = imagecolorallocatealpha($this->im, $color[0], $color[1], $color[2], $color[3]);
            imagettftext($this->im, $size, $angle, (int) ($x + $ox), (int) ($y + $oy), $col, $font, $text);
        } while (!empty($this->gif) && $this->gifNext());
        return $this;
    }

    /* *
 * Switch to the next frame of the GIF and save the current frame */
    protected function gifNext()
    {
        ob_start();
        ob_implicit_flush(0);
        imagegif($this->im);
        $img = ob_get_clean();
        $this->gif->image($img);
        $next = $this->gif->nextImage();
        if ($next) {
            is_resource($this->im) && imagedestroy($this->im);
            $this->im = imagecreatefromstring($next);
            return $next;
        } else {
            is_resource($this->im) && imagedestroy($this->im);
            $this->im = imagecreatefromstring($this->gif->image());
            return false;
        }
    }

    /* *
 * Destroy the image resource when the object is released */
    public function __destruct()
    {
        is_resource($this->im) && imagedestroy($this->im);
    }

}
