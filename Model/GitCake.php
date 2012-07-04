<?php
/**
*
* Git model for the GitCake plugin
* Performs the hard graft of fetching Git data
*
* Licensed under The MIT License
* Redistributions of files must retain the above copyright notice.
*
* @copyright     GitCake Development Team 2012
* @link          http://github.com/pwhittlesea/gitcake
* @package       GitCake.Model
* @since         GitCake v 0.1
* @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
*/

App::import("Vendor", "GitCake.Git", array("file"=>"Git/Git.php"));

class GitCake extends GitCakeAppModel {

    // Reference to our copy of the open git repo
    public $repo = null;

    // We dont need no table
    public $useTable = null;

    /*
     * loadRepo
     * Load the repo at a location
     *
     * @param $base string the path to load
     * @return boolean true if repo is loaded
     */
    public function loadRepo($base = null) {
        if ($base == null) return null;

        try {
            $this->repo = Git::open($base);
        } catch (Exception $e) {
            return null;
        }
        return $this->repo;
    }

    /*
     * createRepo
     * Create a repo at a location
     *
     * @param $base string the path to use
     * @return boolean true if repo is created
     */
    public function createRepo($base = null) {
        if ($base == null) return null;

        if (!file_exists($base)) {
            mkdir($base, 0777);
        }

        $this->repo = Git::create($base, null, true);
        return true;
    }

    /*
     * repoLoaded
     * Check that a repo has been loaded
     *
     * @return boolean true if repo is loaded
     */
    public function repoLoaded() {
        return ($this->repo) ? true : false;
    }

    /*
     * getNodeAtPath
     * Return the details of the current node
     *
     * @param $branch string branch to examine
     * @param $path string the path to examine
     */
    public function getNodeAtPath($branch = 'master', $path = '') {
        if (!$this->repoLoaded()) return null;

        // If we are looking at the root of the project
        if ($path == '') {
            return array(
                'type' => 'tree',
                'hash' => $branch,
            );
        }

        $files = $this->repo->run("ls-tree $branch $path");
        $nodes = explode("\n", $files);

        return $this->_proccessNode($nodes[0]);
    }

    /*
     * listBranches
     * Fetch repos branches
     *
     * @return array list of branches
     */
    public function listBranches() {
        if (!$this->repoLoaded()) return null;

        $branches = $this->repo->run('branch');

        $branches = explode("\n", $branches);
        array_pop($branches);

        foreach ($branches as $a => $branch) {
            $b = preg_split('/\s+/', $branch);
            $branches[$a] = $b[1];
        }
        return $branches;
    }

    /*
     * hasTree
     * Check if repo has a tree
     *
     * @param $hash string the tree to look up
     * @return boolean true if tree exists
     */
    public function hasTree($hash) {
        if (!$this->repoLoaded()) return null;

        try {
            $this->repo->run("ls-tree $hash");
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    /*
     * lsFolder
     * Return the contents of a tree
     *
     * @param $hash string the node to look up
     */
    public function lsFolder($hash) {
        if (!$this->repoLoaded()) return null;

        $files = $this->repo->run('ls-tree ' . $hash);
        $nodes = explode("\n", $files);

        array_pop($nodes);

        foreach ( $nodes as $node ) {
            $return[] = $this->_proccessNode($node);
        }
        return $return;
    }

    /*
     * lsFile
     * Return the contents of a blob
     *
     * @param $hash blob to look up
     */
    public function lsFile($hash) {
        if (!$this->repoLoaded()) return null;

        return $this->repo->run('show ' . $hash);
    }

    /*
     * listCommits
     * Return a list of commits
     *
     * @param $branch string the branch to look up
     * @param $limit int a restriction on the number of commits to return
     */
    public function listCommits($branch = 'master', $limit = 10) {
        if (!$this->repoLoaded()) return null;

        $commits = $this->repo->run('rev-list --all -n'.($limit-1));
        $commits = explode("\n", $commits);

        foreach ($commits as $a => $commit) {
            $commits[$a] = $this->_commitMetadata($commit);
        }
        return $commits;
    }

    /*
     * returnCommit
     * Return a list of commits
     *
     * @param $hash string the hash to look up
     */
    public function showCommit($hash, $color = false) {
        if (!$this->repoLoaded()) return null;

        $result['Commit'] = $this->_commitMetadata($hash);
        $result['Commit']['diff'] = $this->_commitDiff($hash, null, $color);

        return $result;
    }

    /*
     * repoSize
     * Return a list sizes returned by count-objects
     *
     */
    public function repoSize() {
        if (!$this->repoLoaded()) return null;

        $stats = explode("\n", trim($this->repo->run('count-objects -v')));

        foreach ( $stats as $a => $stat ) {
            $temp = preg_split('/:\s+/', $stat);
            unset($stats[$a]);
            $stats[$temp[0]] = $temp[1];
        }
        return $stats;
    }

    /*
     * _commitParent
     * Return the immediate parent of a commit
     *
     * @param $hash string commit to look up
     */
    private function _commitParent($hash) {
        if (!$this->repoLoaded()) return null;

        return preg_split('/\s+/', trim($this->repo->run("--no-pager show -s --format=%P $hash")));
    }

    /*
     * _commitDiff
     * Return the diff for all files altered in a hash
     *
     * @param $hash string commit to look up
     * @param $parent string the parent to compare against
     */
    private function _commitDiff($hash, $parent = null, $color = false) {
        if (!$this->repoLoaded()) return null;

        // If no hash to compare against was provided then use the direct parent
        if ($parent == null) $parent = $this->_commitParent($hash);

        // Do we want pretty color output
        if ($color) 
            $color = '--color';
        else 
            $color = '';

        // For now we are ignoring multiple parents
        $parent = $parent[0];

        // Obtain all the changed files in the diff
        $files = explode("\n", trim($this->repo->run("diff-tree --numstat $parent $hash")));

        $output = array();

        foreach ($files as $file) {
            $line = preg_split('/\s+/', $file);
            $file = $line[2];

            // Gather additions and subtractions stats
            $output[$file]['less'] = $line[1];
            $output[$file]['more'] = $line[0];

            // Store the pretty output from git
            $diff = trim($this->repo->run("diff-tree $color --cc -r $parent $hash -- $file"));

            // Allow for the color character
            $cut = ($color == '') ? strpos($diff, '@@') : strpos($diff, '@@') - 4;

            $output[$file]['diff'] = substr($diff, $cut);
        }

        return $output;
    }

    /*
     * _commitMetadata
     * Return the details for the commit in a hash
     *
     * @param $hash commit to look up
     */
    private function _commitMetadata($hash) {
        if (!$this->repoLoaded()) return null;

        // Magical Git to JSON format
        $pretty = '{"abbv":"%h","hash":"%H","subject":"%s","body":"%b","notes":"%N","date":"%ci","author":{"name":"%cn","email":"%ce"}}';
        $almostpretty = '{"abbv":"%h","hash":"%H","date":"%ci"}';

        $commit = json_decode($this->repo->run("--no-pager show -s --format='".$pretty."' ".$hash), true);
        if (!$commit) {
            // Our super fast JSON solution failed because someone used a " or a ', plan b!
            $commit = json_decode($this->repo->run("--no-pager show -s --format='".$almostpretty."' ".$hash), true);
            $commit['subject'] = trim($this->repo->run("--no-pager show -s --format='%s' ".$hash));
            $commit['body'] = trim($this->repo->run("--no-pager show -s --format='%b' ".$hash));
            $commit['notes'] = trim($this->repo->run("--no-pager show -s --format='%N' ".$hash));
            $commit['author']['name'] = trim($this->repo->run("--no-pager show -s --format='%cn' ".$hash));
            $commit['author']['email'] = trim($this->repo->run("--no-pager show -s --format='%ce' ".$hash));
        }
        return $commit;
    }

    /*
     * _proccessNode
     * Return the details for the node in a hash
     * Essentially converts git row output to array
     *
     * @param $node array the node details
     */
    private function _proccessNode($node) {
        $node = preg_split('/\s+/', $node);

        if ( !isset($node[0]) ||
             !isset($node[1]) ||
             !isset($node[2]) ||
             !isset($node[3]) ) {
            return null;
        }
        return array(
            'permissions' => $node[0],
            'type'        => $node[1],
            'hash'        => $node[2],
            'name'        => $node[3],
        );
    }

}
