<?php 
try {
    require_once __DIR__.'/../auth/credentials.php';

    /**Like PDOStatement but with an exec convenience function that returns the statement again for easy chaining with fetch calls
     * An error is thrown on failure instead of returning false
    */
    class SPDOStatement extends PDOStatement {
        private $dbh;
        public $execution_time = null;
        protected function __construct($dbh) {$this->dbh = $dbh;}
        /**
         * @param array delta if true, sets a value on this prepared statement storing how long its last execution took to complete
         */
        public function exec($params, $deltas=false) {
            $qtime = null;
            if($deltas) $qtime = microtime(true);
            else $this->execution_time = null;
            try {
                $result = $this->execute($params) ? $this : throw new PDOException("Execution failed");
            } catch (PDOException $e) {
                $dbg = $this->debug($params, true, true); 
                //$e->bylines  = explode("\n", $dbg);
                throw $e;
            }
            catch(PDOError $e){throw $e;}
            
            if($deltas) $this->execution_time = microtime(true)- $qtime;
            return $result;
        }

        /**convenience function. binds multiple values in provided associative array*/
        public function bindValues($params) {
            foreach($params as $k => $v) {
                $this->bindValue(":$k", $v);
            }
        }


        public function debug($params = null, $asStr=false, $paramSummary=false) {
            $interpolatedQuery = $this->queryString;
            if($params == null) echo $interpolatedQuery;
            else {
                if($paramSummary) {
                    foreach($params as $key => &$value) {
                        if(is_string($value)) {
                            $value = "...";
                        }
                    }
                }
                foreach ($params as $key => $value) {                
                    $interpolatedQuery = str_replace(":$key", "'" . addslashes($value) . "'", $interpolatedQuery);
                }
                if($asStr) return $interpolatedQuery;
                else echo $interpolatedQuery;
            }
        }
        public function getBuilt($params = null) {
            return $this->debug($params, true);
        }
    }

    /**Like PDO but prepared statements contain an exec convenience function that can be chained with subsequent fetch calls and throw an Error where execute returns false*/
    class SPDO extends PDO {
        public function __construct($dsn, $username = null, $passwd = null, $options = null) {
            parent::__construct($dsn, $username, $passwd, $options);
            $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SPDOStatement::class, [$this]]);
        }
    }

    /**
     * returns the existing global db connection, or makes a new on if none exists
     */
    function getDb() {
        global $db;
        if($db == null)
            $db = makeDb(); 
        return $db;
    }

    function makeDb() {
        try{
            global $db_name, $db_user, $db_pass;
            return new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
        } catch (Exception $e) {
            throw new Exception("Database connection failed");
        }
    }

} catch(Exception $e) {
    throw new Exception("Database connection failed");
}