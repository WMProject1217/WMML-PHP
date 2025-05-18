<?php

class MinecraftLauncher {
    private $mcPath;
    private $versionName;
    private $playerName;
    private $options;
    
    /**
     * Launches Minecraft with the specified parameters
     * @param string $mcPath Path to .minecraft directory
     * @param string $versionName Minecraft version name
     * @param string $playerName Player username
     * @param array $options Launch options
     */
    public function launch(string $mcPath, string $versionName, string $playerName, array $options = []) {
        try {
            $this->mcPath = $this->normalizePath($mcPath);
            $this->versionName = $versionName;
            $this->playerName = $playerName;
            $this->options = array_merge([
                'javaPath' => 'java',
                'memory' => 4096,
                'useSystemMemory' => false
            ], $options);
            
            // Read version JSON file
            $versionJsonPath = $this->joinPaths($this->mcPath, 'versions', $versionName, $versionName . '.json');
            $versionJson = $this->readJsonFile($versionJsonPath);
            
            // Get main class
            $mainClass = $versionJson['mainClass'];
            
            // Build libraries path
            $libraries = $this->buildLibrariesPath($versionJson);
            
            // Build game arguments
            $gameArgs = $this->buildGameArguments($versionJson);
            
            // Build Java command
            $javaCommand = $this->buildJavaCommand($mainClass, $libraries, $gameArgs);
            
            // Execute command
            echo 'Launching Minecraft with command: ' . $javaCommand . PHP_EOL;
            $descriptorspec = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w']   // stderr
            ];
            
            //$process = proc_open($javaCommand, $descriptorspec, $pipes, 'C:\\MinecraftJE1.20.1FabricDX\\', null);
            exec($javaCommand, $output, $exitCode);
            
           /* if (is_resource($process)) {
                // Close stdin as we don't need it
                fclose($pipes[0]);
                
                // Read output and error streams
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);*/
                
                /*while (true) {
                    $read = [$pipes[1], $pipes[2]];
                    $write = null;
                    $except = null;
                    
                    if (stream_select($read, $write, $except, 0, 200000) === false) {
                        break;
                    }
                    
                    foreach ($read as $stream) {
                        if ($stream === $pipes[1]) {
                            $output = fread($stream, 8192);
                            if ($output !== false && $output !== '') {
                                echo $output;
                            }
                        } elseif ($stream === $pipes[2]) {
                            $error = fread($stream, 8192);
                            if ($error !== false && $error !== '') {
                                error_log('stderr: ' . $error);
                            }
                        }
                    }
                    
                    // Check if process is still running
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        break;
                    }
                }
                
                // Close remaining pipes
                fclose($pipes[1]);
                fclose($pipes[2]);
                
                $exitCode = proc_close($process);
                echo 'Minecraft process exited with code ' . $exitCode . PHP_EOL;*/
                
                return $exitCode;
           // } else {
               // throw new RuntimeException('Failed to start Minecraft process');
          //  }
            
        } catch (Exception $error) {
            error_log('Error launching Minecraft: ' . $error->getMessage());
            throw $error;
        }
    }
    
    /**
     * Normalizes path by ensuring it ends with directory separator
     */
    private function normalizePath(string $path): string {
        return rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }
    
    /**
     * Joins multiple path components
     */
    private function joinPaths(string ...$paths): string {
        return implode(DIRECTORY_SEPARATOR, $paths);
    }
    
    /**
     * Builds the libraries classpath
     */
    private function buildLibrariesPath(array $versionJson): string {
        // Start with the version jar
        $result = [$this->joinPaths($this->mcPath, 'versions', $versionJson['id'], $versionJson['id'] . '.jar')];
        
        // Add all libraries
        if (isset($versionJson['libraries']) && is_array($versionJson['libraries'])) {
            foreach ($versionJson['libraries'] as $lib) {
                // Check library rules
                if (!$this->checkLibraryRules($lib)) {
                    continue;
                }
                
                // Get library path
                $libPath = $this->getLibraryPath($lib);
                if ($libPath !== '') {
                    $result[] = $libPath;
                }
            }
        }
        
        return implode(PATH_SEPARATOR, $result);
    }
    
    /**
     * Checks if a library should be included based on rules
     */
    private function checkLibraryRules(array $lib): bool {
        // If no rules, always include
        if (!isset($lib['rules']) || !is_array($lib['rules']) || count($lib['rules']) === 0) {
            return true;
        }
        
        $osName = 'windows';
        $osArch = PHP_INT_SIZE === 8 ? 'x86_64' : 'x86';
        
        $shouldInclude = true;
        
        foreach ($lib['rules'] as $rule) {
            if ($rule['action'] === 'allow') {
                // If no OS specified, allow
                if (!isset($rule['os'])) {
                    $shouldInclude = true;
                    continue;
                }
                
                // Check OS condition
                if ($rule['os']['name'] === $osName) {
                    // Check arch if specified
                    if (isset($rule['os']['arch'])) {
                        $shouldInclude = ($rule['os']['arch'] === $osArch);
                    } else {
                        $shouldInclude = true;
                    }
                } else {
                    $shouldInclude = false;
                }
            } elseif ($rule['action'] === 'disallow') {
                // If no OS specified, disallow
                if (!isset($rule['os'])) {
                    $shouldInclude = false;
                    continue;
                }
                
                // Check OS condition
                if ($rule['os']['name'] === $osName) {
                    $shouldInclude = false;
                }
            }
        }
        
        return $shouldInclude;
    }
    
    /**
     * Gets the path to a library file
     */
    private function getLibraryPath(array $lib): string {
        try {
            $parts = explode(':', $lib['name']);
            $groupPath = str_replace('.', DIRECTORY_SEPARATOR, $parts[0]);
            $artifactId = $parts[1];
            $version = $parts[2];
            
            // Base path
            $basePath = $this->joinPaths($this->mcPath, 'libraries', $groupPath, $artifactId, $version);
            $baseFile = $artifactId . '-' . $version;
            
            // Check for natives
            if (isset($lib['natives']['windows'])) {
                $classifier = str_replace('${arch}', (PHP_INT_SIZE === 8 ? '64' : '32'), $lib['natives']['windows']);
                $nativePath = $this->joinPaths($basePath, $baseFile . '-' . $classifier . '.jar');
                
                if (file_exists($nativePath)) {
                    return $nativePath;
                }
            }
            
            // Default to regular jar
            $jarPath = $this->joinPaths($basePath, $baseFile . '.jar');
            if (file_exists($jarPath)) {
                return $jarPath;
            }
            
            return '';
        } catch (Exception $error) {
            error_log('Error getting library path: ' . $error->getMessage());
            return '';
        }
    }
    
    /**
     * Builds the game arguments string
     */
    private function buildGameArguments(array $versionJson): string {
        $assetsPath = $this->joinPaths($this->mcPath, 'assets');
        $assetsIndex = $versionJson['assets'] ?? '';
        
        $args = '';
        
        // Handle older versions with minecraftArguments
        if (isset($versionJson['minecraftArguments'])) {
            $args = $versionJson['minecraftArguments'];
        }
        
        // Handle newer versions with arguments.game
        if (isset($versionJson['arguments']['game'])) {
            foreach ($versionJson['arguments']['game'] as $arg) {
                if (is_string($arg)) {
                    $args .= ' ' . $arg;
                }
            }
        }
        
        // Replace placeholders
        $replacements = [
            '${auth_player_name}' => $this->playerName,
            '${version_name}' => $this->versionName,
            '${game_directory}' => $this->mcPath,
            '${assets_root}' => $assetsPath,
            '${assets_index_name}' => $assetsIndex,
            '${auth_uuid}' => '00000000-0000-0000-0000-000000000000',
            '${auth_access_token}' => '00000000000000000000000000000000',
            '${user_type}' => 'legacy',
            '${version_type}' => '"WMML 0.1.26"'
        ];
        
        $args = str_replace(array_keys($replacements), array_values($replacements), $args);
        
        return trim($args);
    }
    
    /**
     * Builds the complete Java command
     */
    private function buildJavaCommand(string $mainClass, string $libraries, string $gameArgs): string {
        // Memory settings
        $memorySettings = '';
        if (!$this->options['useSystemMemory'] && $this->options['memory']) {
            $memorySettings = '-Xmx' . $this->options['memory'] . 'M -Xms' . $this->options['memory'] . 'M ';
        }
        
        // Common JVM arguments
        $commonArgs = [
            '-Dfile.encoding=GB18030',
            '-Dsun.stdout.encoding=GB18030',
            '-Dsun.stderr.encoding=GB18030',
            '-Djava.rmi.server.useCodebaseOnly=true',
            '-Dcom.sun.jndi.rmi.object.trustURLCodebase=false',
            '-Dcom.sun.jndi.cosnaming.object.trustURLCodebase=false',
            '-Dlog4j2.formatMsgNoLookups=true',
            '-Dlog4j.configurationFile=' . $this->joinPaths($this->mcPath, 'versions', $this->versionName, 'log4j2.xml'),
            '-Dminecraft.client.jar=' . $this->joinPaths($this->mcPath, 'versions', $this->versionName, $this->versionName . '.jar'),
            '-XX:+UnlockExperimentalVMOptions',
            '-XX:+UseG1GC',
            '-XX:G1NewSizePercent=20',
            '-XX:G1ReservePercent=20',
            '-XX:MaxGCPauseMillis=50',
            '-XX:G1HeapRegionSize=32m',
            '-XX:-UseAdaptiveSizePolicy',
            '-XX:-OmitStackTraceInFastThrow',
            '-XX:-DontCompileHugeMethods',
            '-Dfml.ignoreInvalidMinecraftCertificates=true',
            '-Dfml.ignorePatchDiscrepancies=true',
            '-XX:HeapDumpPath=MojangTricksIntelDriversForPerformance_javaw.exe_minecraft.exe.heapdump',
            '-Djava.library.path=' . $this->joinPaths($this->mcPath, 'versions', $this->versionName, 'natives-windows-x86_64'),
            '-Djna.tmpdir=' . $this->joinPaths($this->mcPath, 'versions', $this->versionName, 'natives-windows-x86_64'),
            '-Dorg.lwjgl.system.SharedLibraryExtractPath=' . $this->joinPaths($this->mcPath, 'versions', $this->versionName, 'natives-windows-x86_64'),
            '-Dio.netty.native.workdir=' . $this->joinPaths($this->mcPath, 'versions', $this->versionName, 'natives-windows-x86_64'),
            '-Dminecraft.launcher.brand=WMML',
            '-Dminecraft.launcher.version=0.1.26'
        ];
        
        // Construct full command
        return 'cmd /K ' . $this->options['javaPath'] . ' ' . $memorySettings . implode(' ', $commonArgs) . 
               ' -cp "' . $libraries . '" ' . $mainClass . ' ' . $gameArgs;
    }
    
    /**
     * Reads a JSON file and parses it
     */
    private function readJsonFile(string $filePath): array {
        if (!file_exists($filePath)) {
            throw new RuntimeException('JSON file not found: ' . $filePath);
        }
        
        $content = file_get_contents($filePath);
        $json = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Error parsing JSON file ' . $filePath . ': ' . json_last_error_msg());
        }
        
        return $json;
    }
}

// Example usage
$launcher = new MinecraftLauncher();
$options = [
    'javaPath' => 'java',
    'memory' => 4096,
    'useSystemMemory' => false
];

try {
    $exitCode = $launcher->launch('.minecraft', '1.20.1', 'Player123', $options);
    echo 'Minecraft process exited with code: ' . $exitCode . PHP_EOL;
} catch (Exception $e) {
    echo 'Failed to launch Minecraft: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}