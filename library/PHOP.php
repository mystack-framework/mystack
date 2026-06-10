<?php

/**
 * ============================================================================
 * Class: PHOP
 * Author: Sakibur Rahman (@sakibweb)
 * ============================================================================
 * 
 * This class is a core component of the MyStack framework.
 * It provides essential functionalities tailored for high performance and security.
 */



class PHOP {

    /**
     * Parse Options and Aliases
     */
    private static function parseOptions($options) {
        $defaults = [
            'width'    => null,  'height' => null,
            'quality'  => 90,    'format' => 'auto',
            'crop'     => false, 'gray'   => false,
            'flip'     => null,  'size'   => null, // e.g., '1MB', '500KB-1MB'
            // Video Specific Options
            'seek'     => 0,     // Start time (e.g., '00:00:05' or 5)
            'duration' => null,  // Duration for GIF (e.g., 3)
            'fps'      => 12     // Frames per second for GIF
        ];

        $aliases = [
            'w' => 'width',   'h' => 'height', 
            'q' => 'quality', 'f' => 'format', 'ext' => 'format',
            'c' => 'crop',    'g' => 'gray',   'bw' => 'gray',
            's' => 'size',    'target' => 'size',
            'start' => 'seek', 'ss' => 'seek', 'time' => 'duration', 't' => 'duration', 'r' => 'fps'
        ];

        $parsed = $defaults;
        foreach ($options as $key => $val) {
            $key = strtolower(trim($key));
            $standard = isset($aliases[$key]) ? $aliases[$key] : $key;
            if (array_key_exists($standard, $parsed)) $parsed[$standard] = $val;
        }
        return $parsed;
    }

    /**
     * Human readable size (KB/MB) to Bytes Converter
     */
    private static function parseBytes($sizeStr) {
        if (!$sizeStr) return 0;
        preg_match('/([\d\.]+)\s*(KB|MB|GB)?/i', trim($sizeStr), $matches);
        $val = (float)$matches[1];
        $unit = strtoupper(isset($matches[2]) ? $matches[2] : 'B');
        switch($unit) {
            case 'KB': return $val * 1024;
            case 'MB': return $val * 1048576;
            case 'GB': return $val * 1073741824;
            default: return $val;
        }
    }

    /**
     * Bulletproof Source Fetcher
     */
    private static function fetchSource($source) {
        if (preg_match('/^data:image\/(\w+);base64,/', $source, $m)) return base64_decode(substr($source, strpos($source, ',') + 1));
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            if (function_exists('curl_init')) {
                $ch = curl_init($source);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false]);
                $data = curl_exec($ch); curl_close($ch);
                if ($data) return $data;
            }
            return @file_get_contents($source);
        }
        if (is_file($source)) return @file_get_contents($source);
        return $source; 
    }

    /**
     * 🌟 THE MAIN IMAGE OPTIMIZER ENGINE (With Target Size AI) 🌟
     */
    public static function img($source, $output = 'preview', $options = []) {
        ini_set('memory_limit', '512M'); 
        
        $opts = self::parseOptions($options);
        $data = self::fetchSource($source);
        if (!$data) throw new \Exception("PHOP: Source unreachable or empty.");

        $orig_img = @imagecreatefromstring($data);
        if (!$orig_img) throw new \Exception("PHOP: Invalid image format.");

        $orig_w = imagesx($orig_img);
        $orig_h = imagesy($orig_img);

        $target_w = $opts['width'] ?: $orig_w;
        $target_h = $opts['height'] ?: $orig_h;

        if ($opts['width'] && !$opts['height']) $target_h = (int)($orig_h * ($opts['width'] / $orig_w));
        elseif (!$opts['width'] && $opts['height']) $target_w = (int)($orig_w * ($opts['height'] / $orig_h));

        $format = strtolower($opts['format']);
        if ($format === 'auto' && strpos($output, '.') !== false) $format = pathinfo($output, PATHINFO_EXTENSION);
        if ($format === 'auto') $format = 'jpg'; 

        $max_bytes = 0; $min_bytes = 0;
        if ($opts['size']) {
            if (strpos($opts['size'], '-') !== false) {
                list($min, $max) = explode('-', $opts['size']);
                $max_bytes = self::parseBytes($max);
            } else {
                $max_bytes = self::parseBytes($opts['size']);
            }
        }

        $current_q = $opts['quality'];
        $scale_factor = 1.0;
        $final_data = '';
        $attempts = 0;
        $mime = 'image/jpeg';

        do {
            $attempts++;
            $calc_w = (int)($target_w * $scale_factor);
            $calc_h = (int)($target_h * $scale_factor);

            $canvas = imagecreatetruecolor($calc_w, $calc_h);
            imagealphablending($canvas, false); imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
            imagefilledrectangle($canvas, 0, 0, $calc_w, $calc_h, $transparent);

            if ($opts['crop']) {
                $src_r = $orig_w / $orig_h; $dst_r = $calc_w / $calc_h;
                if ($src_r > $dst_r) { $crop_w = (int)($orig_h * $dst_r); $crop_h = $orig_h; $src_x = (int)(($orig_w - $crop_w) / 2); $src_y = 0; } 
                else { $crop_w = $orig_w; $crop_h = (int)($orig_w / $dst_r); $src_x = 0; $src_y = (int)(($orig_h - $crop_h) / 2); }
                imagecopyresampled($canvas, $orig_img, 0, 0, $src_x, $src_y, $calc_w, $calc_h, $crop_w, $crop_h);
            } else {
                imagecopyresampled($canvas, $orig_img, 0, 0, 0, 0, $calc_w, $calc_h, $orig_w, $orig_h);
            }

            if ($opts['gray']) imagefilter($canvas, IMG_FILTER_GRAYSCALE);
            if ($opts['flip']) imageflip($canvas, ($opts['flip'] == 'v') ? IMG_FLIP_VERTICAL : (($opts['flip'] == 'b') ? IMG_FLIP_BOTH : IMG_FLIP_HORIZONTAL));

            ob_start();
            switch ($format) {
                case 'png': $mime = 'image/png'; imagepng($canvas, null, (int)(9 - ($current_q / 11))); break;
                case 'webp': $mime = 'image/webp'; imagewebp($canvas, null, $current_q); break;
                case 'gif': $mime = 'image/gif'; imagegif($canvas); break;
                default: imagejpeg($canvas, null, $current_q);
            }
            $final_data = ob_get_clean();
            $current_size = strlen($final_data);
            imagedestroy($canvas);

            if ($max_bytes > 0 && $current_size > $max_bytes) {
                $current_q -= 10;
                if ($current_q < 20) { $current_q = 60; $scale_factor *= 0.85; }
                if ($attempts >= 15) break; 
                continue; 
            }
            break; 
        } while (true);

        imagedestroy($orig_img);

        if ($output === 'raw' || $output === 'return') return $final_data;
        if ($output === 'preview' || $output === 'show') { header("Content-Type: " . $mime); echo $final_data; exit; }
        
        file_put_contents($output, $final_data);
        return $output; 
    }

    /**
     * 🎬 FULL POTENTIAL VIDEO ENGINE (Video to High-Quality GIF / Single Image)
     * Requires: FFmpeg installed on the server.
     */
    public static function video($source, $output = 'preview', $options = []) {
        if (!function_exists('exec')) throw new \Exception("PHOP Video: exec() function is disabled.");
        
        $opts = self::parseOptions($options);
        $format = strtolower($opts['format']);
        
        if ($format === 'auto' && strpos($output, '.') !== false) {
            $format = pathinfo($output, PATHINFO_EXTENSION);
        }
        if ($format === 'auto') $format = 'gif'; // Fallback to GIF

        // Scale Handling
        $w = $opts['width'] ?: '-1';
        $h = $opts['height'] ?: '-1';
        if ($w === '-1' && $h === '-1' && in_array($format, ['gif', 'webp'])) $w = 480; // Default width for GIF to save memory
        $scale_filter = "scale={$w}:{$h}:flags=lanczos";

        // Build Base Command
        $cmd = "ffmpeg -y -hide_banner -loglevel error ";

        // Fast Seeking (Applying before Input)
        if ($opts['seek']) $cmd .= "-ss " . escapeshellarg($opts['seek']) . " ";

        $cmd .= "-i " . escapeshellarg($source) . " ";

        // Output & Temp File Logic
        $is_temp = false;
        $out_path = $output;
        if (in_array($output, ['preview', 'show', 'raw', 'return'])) {
            $is_temp = true;
            $out_path = tempnam(sys_get_temp_dir(), 'phop_vid_') . '.' . $format;
        }

        // Logic Based on Output Type
        if (in_array($format, ['gif', 'webp'])) {
            // HIGH-QUALITY GIF MAKER (Using palettegen & paletteuse for crystal clear colors)
            $duration = $opts['duration'] ?: 3; // Default 3 sec GIF
            $fps = $opts['fps'] ?: 12;

            $cmd .= "-t " . escapeshellarg($duration) . " ";
            // The magic filter for High-Quality GIF
            $complex_filter = "fps={$fps},{$scale_filter},split[s0][s1];[s0]palettegen[p];[s1][p]paletteuse";
            $cmd .= "-filter_complex " . escapeshellarg($complex_filter) . " ";
            
            if ($format === 'webp') {
                $cmd .= "-vcodec libwebp -lossless 0 -qscale " . escapeshellarg($opts['quality']) . " -loop 0 ";
            }
        } else {
            // EXTRACT SINGLE FRAME (Image like JPG/PNG)
            $cmd .= "-vframes 1 -q:v 2 ";
            if ($w !== '-1' || $h !== '-1') $cmd .= "-vf " . escapeshellarg($scale_filter) . " ";
        }

        $cmd .= escapeshellarg($out_path) . " 2>&1";
        
        // Execute FFmpeg
        exec($cmd, $cmd_output, $return_var);

        if ($return_var !== 0 || !file_exists($out_path)) {
            throw new \Exception("PHOP FFmpeg Error: " . implode("\n", $cmd_output));
        }

        // Handle File Output
        if ($is_temp) {
            $data = file_get_contents($out_path);
            @unlink($out_path); // Remove temp file
            
            if ($output === 'raw' || $output === 'return') return $data;
            
            $mime = 'image/' . ($format === 'jpg' ? 'jpeg' : $format);
            header("Content-Type: " . $mime);
            echo $data;
            exit;
        }

        return $out_path;
    }

    /**
     * 🗜️ ZIP Compressor
     */
    public static function zip($source, $output_path) {
        if (!extension_loaded('zip')) throw new \Exception("PHOP: ZIP extension not enabled.");
        $zip = new ZipArchive();
        if ($zip->open($output_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            if (is_dir($source)) {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::LEAVES_ONLY);
                foreach ($files as $name => $file) {
                    if (!$file->isDir()) $zip->addFile($file->getRealPath(), substr($file->getRealPath(), strlen(realpath($source)) + 1));
                }
            } else { $zip->addFile($source, basename($source)); }
            $zip->close(); return $output_path;
        }
        return false;
    }

    /**
     * 📝 Text & Data Optimizer
     */
    public static function text($data, $action = 'compress') {
        if ($action === 'compress') return base64_encode(gzdeflate($data, 9));
        if ($action === 'decompress') return gzinflate(base64_decode($data));
        if ($action === 'json_minify') return json_encode(json_decode($data, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        if ($action === 'html') return preg_replace(['/\>[^\S ]+/s', '/[^\S ]+\</s', '/(\s)+/s', '/<!--(.|\s)*?-->/'], ['>', '<', '\\1', ''], $data);
        if ($action === 'css') return preg_replace(['!/\*[^*]*\*+([^/][^*]*\*+)*/!', '/\s*([{}|:;,])\s+/'], ['', '$1'], str_replace(["\r\n", "\r", "\n", "\t"], '', $data));
        
        return $data;
    }
}
?>