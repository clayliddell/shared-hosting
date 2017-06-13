<?php
  namespace SharedHosting\Services;

  use \SharedHosting\Interfaces\Service;
  use \SharedHosting\Utility\Validation;

  class FPMPool implements Service {
    protected $db       = null;
    protected $files    = [];
    protected $fwd      = true;
    protected $username = null;
    protected $versions = [];

    public function __construct(string $username) {
      $this->db = $GLOBALS['db'];
      // Check the provided username for validity
      Validation::username($username);
      // Assign the provided username to an internal property
      $this->username = $username;
      $this->versions = $this->fetchVersions();
      $this->files    = $this->fetchFiles();
    }

    protected function fetchFiles(): array {
      $result = []; // Iterate over each installed version of PHP
      foreach ($this->versions as $version)
        // Create a path key and content value for this version
        $result['/etc/php/'.$version.'/fpm/pool.d/'.$this->username.'.conf'] =
          implode("\n", [ '['.$this->username.']',
            'include = /etc/php/'.$version.'/fpm/common.conf', null]);
      // Return the resulting array of paths and contents
      return $result;
    }

    protected function fetchVersions() {
      // Return a list of installed PHP versions based on available binaries
      return preg_split('/\s+/', trim(shell_exec('ls /usr/sbin/php-fpm?.? | '.
        'sed \'s|/usr/sbin/php-fpm||g\'')));
    }

    public function fetchResult(bool $batch): bool {
      // Return whether the forward or reverse methods succeeded
      return ( $this->fwd &&  $this->exists() && ($batch || $this->reload())) ||
             (!$this->fwd && !$this->exists() && ($batch || $this->reload()));
    }

    public function exists(): bool {
      // Return false for any file that doesn't exist
      foreach (array_keys($this->files) as $file)
        if (!is_file($file)) return false;
      // If we reach this point, then all files exist
      return true;
    }

    public function forward(): void {
      // Keep track of the last direction in the internal state
      $this->fwd = true;
      // Install the configuration files for this user's pools
      foreach ($this->files as $file => $content) {
        is_dir(basename($file)) || mkdir(basename($file), 0755, true);
        file_put_contents($file, $content);
      }
    }

    public function reverse(): void {
      // Keep track of the last direction in the internal state
      $this->fwd = false;
      // Remove the configuration files for this user's pools
      foreach (array_keys($this->files) as $file) unlink($file);
    }

    public function reload(): bool {
      $result = true;
      // Restart the PHP FPM services
      foreach ($this->versions as $version) {
        system('systemctl restart php'.escapeshellarg($version).
          '-fpm', $tempResult);
        $result = $result && ($tempResult === 0);
      } return $result;
    }
  }
