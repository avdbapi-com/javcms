<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\util;

class Download
{
    var $filepath;
    var $downname;
    var $ErrInfo;
    var $is_attachment = false;
    var $_LANG = array(
            'err' => 'Error',
            'args_empty' => 'No file path was provided.',
            'file_not_exists' => 'The file does not exist!',
            'file_not_readable' => 'The file cannot be read.',
        );
    var $MIMETypes = array( 
            'ez' => 'application/andrew-inset', 
            'hqx' => 'application/mac-binhex40', 
            'cpt' => 'application/mac-compactpro', 
            'doc' => 'application/msword', 
            'bin' => 'application/octet-stream', 
            'dms' => 'application/octet-stream', 
            'lha' => 'application/octet-stream', 
            'lzh' => 'application/octet-stream', 
            'exe' => 'application/octet-stream', 
            'class' => 'application/octet-stream', 
            'so' => 'application/octet-stream', 
            'dll' => 'application/octet-stream', 
            'oda' => 'application/oda', 
            'pdf' => 'application/pdf', 
            'ai' => 'application/postscrīpt', 
            'eps' => 'application/postscrīpt', 
            'ps' => 'application/postscrīpt', 
            'smi' => 'application/smil', 
            'smil' => 'application/smil', 
            'mif' => 'application/vnd.mif', 
            'xls' => 'application/vnd.ms-excel', 
            'ppt' => 'application/vnd.ms-powerpoint', 
            'wbxml' => 'application/vnd.wap.wbxml', 
            'wmlc' => 'application/vnd.wap.wmlc', 
            'wmlsc' => 'application/vnd.wap.wmlscrīptc', 
            'bcpio' => 'application/x-bcpio', 
            'vcd' => 'application/x-cdlink', 
            'pgn' => 'application/x-chess-pgn', 
            'cpio' => 'application/x-cpio', 
            'csh' => 'application/x-csh', 
            'dcr' => 'application/x-director', 
            'dir' => 'application/x-director', 
            'dxr' => 'application/x-director', 
            'dvi' => 'application/x-dvi', 
            'spl' => 'application/x-futuresplash', 
            'gtar' => 'application/x-gtar', 
            'hdf' => 'application/x-hdf', 
            'js' => 'application/x-javascrīpt', 
            'skp' => 'application/x-koan', 
            'skd' => 'application/x-koan', 
            'skt' => 'application/x-koan', 
            'skm' => 'application/x-koan', 
            'latex' => 'application/x-latex', 
            'nc' => 'application/x-netcdf', 
            'cdf' => 'application/x-netcdf', 
            'sh' => 'application/x-sh', 
            'shar' => 'application/x-shar', 
            'swf' => 'application/x-shockwave-flash', 
            'sit' => 'application/x-stuffit', 
            'sv4cpio' => 'application/x-sv4cpio', 
            'sv4crc' => 'application/x-sv4crc', 
            'tar' => 'application/x-tar', 
            'tcl' => 'application/x-tcl', 
            'tex' => 'application/x-tex', 
            'texinfo' => 'application/x-texinfo', 
            'texi' => 'application/x-texinfo', 
            't' => 'application/x-troff', 
            'tr' => 'application/x-troff', 
            'roff' => 'application/x-troff', 
            'man' => 'application/x-troff-man', 
            'me' => 'application/x-troff-me', 
            'ms' => 'application/x-troff-ms', 
            'ustar' => 'application/x-ustar', 
            'src' => 'application/x-wais-source', 
            'xhtml' => 'application/xhtml+xml', 
            'xht' => 'application/xhtml+xml', 
            'zip' => 'application/zip', 
            'au' => 'audio/basic', 
            'snd' => 'audio/basic', 
            'mid' => 'audio/midi', 
            'midi' => 'audio/midi', 
            'kar' => 'audio/midi', 
            'mpga' => 'audio/mpeg', 
            'mp2' => 'audio/mpeg', 
            'mp3' => 'audio/mpeg',
            'wma' => 'audio/mpeg', 
            'aif' => 'audio/x-aiff', 
            'aiff' => 'audio/x-aiff', 
            'aifc' => 'audio/x-aiff', 
            'm3u' => 'audio/x-mpegurl', 
            'ram' => 'audio/x-pn-realaudio', 
            'rm' => 'audio/x-pn-realaudio', 
            'rpm' => 'audio/x-pn-realaudio-plugin', 
            'ra' => 'audio/x-realaudio', 
            'wav' => 'audio/x-wav', 
            'pdb' => 'chemical/x-pdb', 
            'xyz' => 'chemical/x-xyz', 
            'bmp' => 'image/bmp', 
            'gif' => 'image/gif', 
            'ief' => 'image/ief', 
            'jpeg' => 'image/jpeg', 
            'jpg' => 'image/jpeg', 
            'jpe' => 'image/jpeg', 
            'png' => 'image/png', 
            'tiff' => 'image/tiff', 
            'tif' => 'image/tiff', 
            'djvu' => 'image/vnd.djvu', 
            'djv' => 'image/vnd.djvu', 
            'wbmp' => 'image/vnd.wap.wbmp', 
            'ras' => 'image/x-cmu-raster', 
            'pnm' => 'image/x-portable-anymap', 
            'pbm' => 'image/x-portable-bitmap', 
            'pgm' => 'image/x-portable-graymap', 
            'ppm' => 'image/x-portable-pixmap', 
            'rgb' => 'image/x-rgb', 
            'xbm' => 'image/x-xbitmap', 
            'xpm' => 'image/x-xpixmap', 
            'xwd' => 'image/x-xwindowdump', 
            'igs' => 'model/iges', 
            'iges' => 'model/iges', 
            'msh' => 'model/mesh', 
            'mesh' => 'model/mesh', 
            'silo' => 'model/mesh', 
            'wrl' => 'model/vrml', 
            'vrml' => 'model/vrml', 
            'css' => 'text/css', 
            'html' => 'text/html', 
            'htm' => 'text/html', 
            'asc' => 'text/plain', 
            'txt' => 'text/plain', 
            'rtx' => 'text/richtext', 
            'rtf' => 'text/rtf', 
            'sgml' => 'text/sgml', 
            'sgm' => 'text/sgml', 
            'tsv' => 'text/tab-separated-values', 
            'wml' => 'text/vnd.wap.wml', 
            'wmls' => 'text/vnd.wap.wmlscrīpt', 
            'etx' => 'text/x-setext', 
            'xsl' => 'text/xml', 
            'xml' => 'text/xml', 
            'mpeg' => 'video/mpeg', 
            'mpg' => 'video/mpeg', 
            'mpe' => 'video/mpeg', 
            'qt' => 'video/quicktime', 
            'mov' => 'video/quicktime', 
            'mxu' => 'video/vnd.mpegurl', 
            'avi' => 'video/x-msvideo', 
            'movie' => 'video/x-sgi-movie', 
            'wmv' => 'application/x-mplayer2',
            'ice' => 'x-conference/x-cooltalk', 
        ); 
    
    function Download($filepath='',$downname='')
    {
        if($filepath == '' AND !$this->filepath)
        {
            $this->ErrInfo = $this->_LANG['err'] . ':' . $this->_LANG['args_empty'];
            return false;
        }
        if($filepath == '') $filepath = $this->filepath;
        if(!file_exists($filepath))
        {
            $this->ErrInfo = $this->_LANG['err'] . ':' . $this->_LANG['file_not_exists'];
            return false;
        }
        if($downname == '' AND !$this->downname) $downname = $filepath;
        if($downname == '') $downname = $this->downname;
        $fileExt = substr(strrchr($filepath, '.'), 1); 
        $fileType = $this->MIMETypes[$fileExt] ? $this->MIMETypes[$fileExt] : 'application/octet-stream'; 
        $isImage = False; 
        $imgInfo = @getimagesize($filepath); 
        if ($imgInfo[2] && $imgInfo['bits']) 
        { 
            $fileType = $imgInfo['mime'];      
            $isImage = True; 
        } 
        if($this->is_attachment) 
        {
            $attachment = 'attachment';    
        }
        else 
        {
            $attachment = $isImage ? 'inline' : 'attachment'; 
        }
        if (is_readable($filepath)) 
        { 
            ob_end_clean(); 
            header('Cache-control: max-age=31536000'); 
            header('Expires: ' . gmdate('D, d M Y H:i:s', time()+31536000) . ' GMT'); 

            header('Content-Encoding: none'); 

            header('Content-type: ' . $fileType); 
            /* Use attachment to request a download and suggest the saved filename.
 Use inline to let the browser display supported content, such as images or text. */ 
            header('Content-Disposition: ' . $attachment . '; filename=' . $downname); 
            /* Send the file size in bytes 
 for the browser's download progress display. */ 
            header('Content-Length: ' . filesize($filepath)); 
            // Open file (binary read-only mode)
            $fp = fopen($filepath, 'rb'); 
            // output file
            fpassthru($fp); 
            // Close a file
            fclose($fp); 
            return true;
        }
        else 
        {
            $this->error = $this->_LANG['err'] . ':' . $this->_LANG['file_not_readable'];
            return false;
        }
    }
}