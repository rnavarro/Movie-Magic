<?php

/*
 * @Author Robert Navarro <crshman@gmail.com>
 * @Version 1.2.1
 * @Date 12/2/09
 * 
 * This little script makes some super crazy movie magic!
 * It queries imdb for the title and release year of the movie
 * If the movie is an "asian" film (based on my country criteria below)
 * it moves those into a different directory and translates the titles
 * to the US english titles.
 * 
 * The script also queries TMDB for movie posters to place in each
 * movie directory
 * 
 * Original directory scanner from:
 * http://lixlpixel.org/recursive_function/php/recursive_directory_scan/
 * 
 * Requres the imdbphp class from:
 * http://sourceforge.net/projects/imdbphp/
 */

require(dirname(__FILE__) . "/imdbphp2/imdb.class.php");
require(dirname(__FILE__) . "/config.php");

$debug    = false;
$simulate = false;

if (array_search("debug", $argv)) {
    echo "*** Debuging Enabled ***\n\n";
    $debug = true;
}

if (array_search("sim", $argv)) {
    echo "*** Simulation Mode ***\n\n";
    $simulate = true;
}

// Get TNMDB config
$configuration_request_url = "http://api.themoviedb.org/3/configuration";
$configuration_request_url .= "?api_key={$tmdb_api_key}";

$curlSession = curl_init();
curl_setopt($curlSession, CURLOPT_URL, $configuration_request_url);
curl_setopt($curlSession, CURLOPT_BINARYTRANSFER, true);
curl_setopt($curlSession, CURLOPT_RETURNTRANSFER, true);

$jsonConfigurationData = json_decode(curl_exec($curlSession), true);
curl_close($curlSession);

$imageBaseUrl = "{$jsonConfigurationData['images']['base_url']}original/";

$tree = scan_directory_recursively($source_dir);

foreach ($tree as $folder) {
    unset($movie_files);
    if (strstr($folder['name'], '_FAILED_')) {
        continue;
    }
    if (strstr($folder['name'], '_UNPACK_')) {
        continue;
    }
    if (empty($folder['content'])) {
        continue;
    }
    echo $folder['name'];
    //print_r($folder);
    $nfo_path    = '';
    $movie_path  = '';
    $title_path  = '';
    $movie_files = array();
    foreach ($folder['content'] as $file) {
        if ($file['kind'] == 'directory') {
            echo " || Looks like we found a directory!";
            break;
        } elseif ($file['name'] == 'movie.nfo' && empty($nfo_path)) {
            $nfo_path = $file['path'];
        } elseif (strtolower($file['extension']) == 'nfo' && empty($nfo_path)) {
            $nfo_path = $file['path'];
        } elseif (in_array(strtolower($file['extension']), $movie_extensions)) {
            $movie_path    = $file['path'];
            $movie_files[] = $file['name'];
        } elseif ($file['name'] == 'title.txt' && !empty($title_path)) {
            $title_path = $file['path'];
        }
    }

    // See if we have a nfo file
    if (!empty($nfo_path)) {
        $handle   = fopen($nfo_path, "r");
        $contents = fread($handle, filesize($nfo_path));
        fclose($handle);

        $imdb_id = '';
        // Search the nfo for the imdb id
        preg_match('/imdb\.com\/title\/tt(\d*)/', $contents, $matches1);
        preg_match('/imdb\.com\/Title\?(\d*)/', $contents, $matches2);
        $imdb_id = array_merge($matches1, $matches2);
        if (empty($imdb_id[1])) {
            echo " || No IMDB Link!\n";
            continue;
        } else {
            $imdb_id = $imdb_id[1];
        }
        echo ' {' . $imdb_id . '}';

        // Get the movie poster
        $request_url = "http://api.themoviedb.org/3/movie";
        $request_url .= "/tt{$imdb_id}";
        $request_url .= "?api_key={$tmdb_api_key}";
        $request_url .= "&append_to_response=images";

        $curlSession = curl_init();
        curl_setopt($curlSession, CURLOPT_URL, $request_url);
        curl_setopt($curlSession, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($curlSession, CURLOPT_RETURNTRANSFER, true);

        $jsonData = json_decode(curl_exec($curlSession), true);
        curl_close($curlSession);

        // Movie Not found in TMDB
        if (array_key_exists('status_code', $jsonData)) {
            echo " || {$jsonData['status_message']}!\n";
            continue;
        }

        // See if we already have a poster
        $poster = substr($movie_path, 0, -3);
        $poster = $poster . "jpg";
        if (!file_exists($poster)) {

            $posters   = array();
            $backdrops = array();
            foreach ($jsonData['images']['posters'] as $poster) {
                $posters[] = $poster;
                break;
            }

            foreach ($jsonData['images']['backdrops'] as $backdrop) {
                $backdrops[] = $backdrop;
                break;
            }

            if (empty($posters)) {
                if (empty($backdrops)) {
                    echo " || No Poster URL!\n";
                } else {
                    echo " || No Poster URL! (But there is a backdrop!)\n";
                }
                continue;
            }

            if (empty($backdrops)) {
                echo " || No Backdrop URL! (But there is a poster!)\n";
                continue;
            }

            $poster_url = array_pop(array_reverse($posters));
            $poster_url = $imageBaseUrl . $poster_url['file_path'];

            $return = get_headers($poster_url, 1);
            if (stristr($return[0], '404')) {
                echo "|| Looks like the Poster URL is bad? (404) [$poster_url]\n";
                continue;
            }

            $poster_url = escapeshellarg($poster_url);

            $backdrop_url = array_pop(array_reverse($backdrops));
            $backdrop_url = $imageBaseUrl . $backdrop_url['file_path'];

            $return = get_headers($backdrop_url, 1);
            if (stristr($return[0], '404')) {
                echo "|| Looks like the Backdrop URL is bad? (404) [$backdrop_url]\n";
                continue;
            }

            $backdrop_url = escapeshellarg($backdrop_url);

            // Save the movie poster
            $poster  = substr($movie_path, 0, -3);
            $poster1 = $poster . "jpg";
            $poster2 = $poster . "tbn";
            $poster1 = escapeshellarg($poster1);
            $poster2 = escapeshellarg($poster2);
            $cmd     = 'wget -q ' . $poster_url . ' -O ' . $poster1;
            echo " [Poster]";
            if ($simulate) {
                echo "\n$cmd\n";
            } else {
                exec($cmd, $return);
            }

            // Copy poster .jpg to poster .tbn
            $cmd = 'cp ' . $poster1 . ' ' . $poster2;
            if ($simulate) {
                echo "\n$cmd\n";
            } else {
                exec($cmd);
            }

            // Copy poster .jpg to poster folder.jpg
            $cmd = 'cp ' . $poster1 . ' ' . escapeshellarg($folder['path'] . '/folder.jpg');
            if ($simulate) {
                echo "\n$cmd\n";
            } else {
                exec($cmd);
            }

            // Save the backdrop
            $cmd = 'wget -q ' . $backdrop_url . ' -O ' . escapeshellarg($folder['path'] . '/backdrop.jpg');
            echo " [Backdrop]";
            if ($simulate) {
                echo "\n$cmd\n";
            } else {
                exec($cmd, $return);
            }
        }

        // Figure out the movie screen size

        // Check to see if we have mediainfo installed
        // Currently this is only for linux, let me know if you want windows support...
        exec('mediainfo --version', $output, $return);

        // mediainfo exists
        if ($return == 255) {
            if (is_array($movie_files)) {
                foreach ($movie_files as $mfile) {
                    $mpath = escapeshellarg($folder['path'] . '/' . $mfile);

                    $width       = Array();
                    $height      = Array();
                    $scan_type   = Array();
                    $screen_size = "";

                    exec('mediainfo --Inform=Video\;%Width% ' . $mpath, $width);
                    exec('mediainfo --Inform=Video\;%Height% ' . $mpath, $height);
                    exec('mediainfo --Inform=Video\;%ScanType% ' . $mpath, $scan_type);

                    if ($width[0] >= 1280) {
                        $screen_size = '720';
                    }
                    if ($width[0] >= 1920) {
                        $screen_size = '1080';
                    }

                    // If the film isn't HD reset the screensize to nothing
                    if ($screen_size != '') {
                        if ($scan_type[0] == 'Progressive') {
                            $screen_size .= 'p';
                        } else {
                            $screen_size .= 'i';
                        }

                        $screen_size = 'HD ' . $screen_size;
                    } else {
                        $screen_size = "";
                    }
                }
            }
            // Looks like mediainfo isn't installed, try to grab the video size from the file/folder name
        } else {
            $screen_size = "";

            if (is_array($movie_files)) {
                foreach ($movie_files as $mfile) {

                    if (strstr($mfile, '720p')) {
                        $screen_size = "HD 720p";
                    } elseif (strstr($mfile, '1080p')) {
                        $screen_size = "HD 1080p";
                    }
                }
            }

            if (strstr($folder['name'], '720p')) {
                $screen_size = "HD 720p";
            } elseif (strstr($folder['name'], '1080p')) {
                $screen_size = "HD 1080p";
            }
        }

        echo ' {' . $screen_size . '} ';

        // Query IMDB for the title and year
        $movie = new imdb ($imdb_id);
        $movie->setid($imdb_id);

        // Lets check the movie language
        error_reporting(0); // Ugly hack to get rid of that stupid notice...that comes up when no language is defined...
        $language = $movie->language();
        error_reporting(1);

        // Is it a foreign language film?
        $foreign = false;
        if ($language != 'English') {
            $foreign = true;
        }

        $title = '';
        if (!empty($title_path)) {
            $handle   = fopen($title_path, "r");
            $contents = fread($handle, filesize($title_path));
            fclose($handle);

            $splits = explode("\n", $contents);

            if (isset($splits[0])) {
                $title = $splits[0];
            } else {
                echo "|| title.txt file found but we couldn't parse it!\n";
                continue;
            }
        } else {
            // It is foreign, Find an alternate title
            if ($foreign) {
                echo '[' . $movie->language() . '] ';
                $aka = $movie->alsoknow();
                // Do we have a specific USA title?
                foreach ($aka as $name) {
                    if ($name['country'] == 'USA') {
                        $title = $name['title'];
                    }
                }

                $titles = array();
                // Round up all the international english titles
                if (empty($title)) {
                    foreach ($aka as $name) {
                        if (stristr($name['country'], 'international')) {
                            if (stristr($name['country'], 'english')) {
                                $titles[] = $name;
                            } elseif (stristr($name['comment'], 'english title')) {
                                $titles[] = $name;
                            }
                            // These titles *may* work, but they're not guaranteed to be english like the above
                        } elseif (stristr($name['comment'], 'english title')) {
                            $potentials[] = $name;
                        }
                    }
                    if (!empty($titles[0]['title']) && count($titles) == 1) {
                        $title = $titles[0]['title'];
                    } elseif (count($titles) > 1) {
                        $potentials = $titles;
                    }
                }

                // Last try....grab the title from TMDB
                if (empty($title)) {
                    if (!empty($jsonData['title'])) {
                        $title = $jsonData['title'];
                    }
                }

                // Couldn't find anything good....let the user pick
                if (empty($title)) {
                    if (empty($potentials)) {
                        echo "|| Couldn't Pick a title! You choose!\n";
                    } else {
                        echo "|| Couldn't Pick a title! You choose! Maybe: ";
                        $size = count($potentials);
                        foreach ($potentials as $potential) {
                            echo "\"" . $potential['title'] . "\"";
                            if ($size > 1) {
                                echo " or ";
                                $size--;
                            }
                        }
                        echo "\n";
                    }
                    unset($potential);
                    unset($potentials);
                    continue;
                }
                // Domestic, use the regular title
            } else {
                $title = $movie->title();
            }
        }

        echo "===> ";
        $new_name = '';
        if (stristr($title, '<div class="info-content">')) {
            $title = substr($title, strlen('<div class="info-content">'));
        }
        // Stupid string replacements for windows
        $title    = str_replace(':', ';', $title);
        $title    = str_replace('?', '', $title);
        $title    = str_replace('*', '_', $title);
        $title    = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
        $new_name = $title . ' (' . $movie->year() . ')';

        if (strlen($screen_size) > 0) {
            $new_name .= " {$screen_size}";
        }

        echo $new_name;

        // Rename folders!
        // Check to see if it's an asian film
        if ($foreign && in_array($language, $asian_languages) && $en_asian) {
            echo ' [AZN] ';
            $new_dest = $dest_dir_asian . '/' . $new_name . '';
            // It's not so proceed as normal
        } else {
            $new_dest = $dest_dir . '/' . $new_name . '';
        }
        if (file_exists($new_dest)) {
            echo "|| Folder exists!\n\n";
            continue;
        }
        $cmd = 'mv ' . escapeshellarg($folder['path']) . ' ' . escapeshellarg($new_dest);

        if ($simulate) {
            echo "\n" . $cmd . "\n";
        } else {
            exec($cmd);
        }

    } else {
        echo "|| No NFO file!";
    }
    echo "\n";
}

// ------------------------------------------------------------

function scan_directory_recursively($directory, $filter = false)
{
    // if the path has a slash at the end we remove it here
    if (substr($directory, -1) == '/') {
        $directory = substr($directory, 0, -1);
    }

    // if the path is not valid or is not a directory ...
    if (!file_exists($directory) || !is_dir($directory)) {
        // ... we return false and exit the function
        return false;

        // ... else if the path is readable
    } elseif (is_readable($directory)) {
        // we open the directory
        $directory_list = opendir($directory);
        $directory_tree = array();

        // and scan through the items inside
        while (false !== ($file = readdir($directory_list))) {
            // if the filepointer is not the current directory
            // or the parent directory
            if ($file != '.' && $file != '..') {
                // we build the new path to scan
                $path = $directory . '/' . $file;

                // if the path is readable
                if (is_readable($path)) {
                    // we split the new path by directories
                    $subdirectories = explode('/', $path);

                    // if the new path is a directory
                    if (is_dir($path)) {
                        // add the directory details to the file list
                        $directory_tree[] = array(
                            'path'    => $path,
                            'name'    => end($subdirectories),
                            'kind'    => 'directory',
                            // we scan the new path by calling this function
                            'content' => scan_directory_recursively($path, $filter)
                        );

                        // if the new path is a file
                    } elseif (is_file($path)) {
                        // get the file extension by taking everything after the last dot
                        $extension = end(explode('.', end($subdirectories)));

                        // if there is no filter set or the filter is set and matches
                        if ($filter === false || $filter == $extension) {
                            // add the file details to the file list
                            $directory_tree[] = array(
                                'path'      => $path,
                                'name'      => end($subdirectories),
                                'extension' => $extension,
                                'size'      => filesize($path),
                                'kind'      => 'file'
                            );
                        }
                    }
                }
            }
        }
        // close the directory
        closedir($directory_list);

        // return file list
        return $directory_tree;

        // if the path is not readable ...
    } else {
        // ... we return false
        return false;
    }
}