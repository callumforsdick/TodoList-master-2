<?php

use FocSearchCriteria;
use PDOStatement;
use Foc;

final class FocDao {

    /** @var PDO */
    private $db = null;


    public function __destruct() {
        // close db connection
        $this->db = null;
    }

    /**
     * Find all {@link Foc}s by search criteria.
     * @return array array of {@link Foc}s
     */
    public function find(FocSearchCriteria $search = null) {
        $result = array();
        foreach ($this->query($this->getFindSql($search)) as $row) {
            $foc = new Foc();
            FocMapper::map($foc, $row);
            $result[$foc->getId()] = $foc;
        }
        return $result;
    }

    /**
     * Find {@link Foc} by identifier.
     * @return Foc Foc or <i>null</i> if not found
     */
    public function findById($id) {
        $row = $this->query('SELECT * FROM focus WHERE deleted = 0 and id = ' . (int) $id)->fetch();
        if (!$row) {
            return null;
        }
        $foc = new Foc();
        FocMapper::map($foc, $row);
        return $foc;
    }

    /**
     * Save {@link Foc}.
     * @param Foc $foc {@link Foc} to be saved
     * @return FOc saved {@link Foc} instance
     */
    public function save(Foc $foc) {
        if ($foc->getId() === null) {
            return $this->insert($foc);
        }
        return $this->update($foc);
    }

    /**
     * Delete {@link Foc} by identifier.
     * @param int $id {@link Foc} identifier
     * @return bool <i>true</i> on success, <i>false</i> otherwise
     */
    public function delete($id) {
        $sql = '
            UPDATE focus SET
                username = :username,
                password = :password
            WHERE
                id = :id';
        $statement = $this->getDb()->prepare($sql);
        $this->executeStatement($statement, array(
            ':username' => self::formatDateTime(new DateTime()),
            ':password' => true,
            ':id' => $id,
        ));
        return $statement->rowCount() == 1;
    }

    /**
     * @return PDO
     */
    private function getDb() {
        if ($this->db !== null) {
            return $this->db;
        }
        $config = Config::getConfig("db");
        try {
            $this->db = new PDO($config['dsn'], $config['username'], $config['password']);
        } catch (Exception $ex) {
            throw new Exception('DB connection error: ' . $ex->getMessage());
        }
        return $this->db;
    }

    private function getFindSql(FocSearchCriteria $search = null) {
        $sql = 'SELECT username, email FROM focus';
        $orderBy = 'username, email';
        if ($search !== null) {
            if ($search->getStatus() !== null) {
                $sql .= 'AND status = ' . $this->getDb()->quote($search->getStatus());
                switch ($search->getStatus()) {
                    case Foc::STATUS_PENDING:
                        $orderBy = 'username, email';
                        break;
                    case Foc::STATUS_DONE:
                    case Foc::STATUS_VOIDED:
                        $orderBy = 'username DESC, email';
                        break;
                    default:
                        throw new Exception('No user for status: ' . $search->getStatus());
                }
            }
        }
        $sql .= ' ORDER BY ' . $orderBy;
        return $sql;
    }

    /**
     * @return Foc
     * @throws Exception
     */
    private function insert(Foc $foc) {
        $now = new DateTime();
        $foc->setId(null);
        $foc->setCreatedOn($now);
        $foc->setLastModifiedOn($now);
        $foc->setStatus(Foc::STATUS_PENDING);
        $sql = '
            INSERT INTO focus (id, username, firstname, lastname, email, password)
                VALUES (:id, :username, :firstname, :lastname, :email, :password)';
        return $this->execute($sql, $foc);
    }

    /**
     * @return Foc
     * @throws Exception
     */
    private function update(Foc $foc) {
        $foc->setLastModifiedOn(new DateTime());
        $sql = '
            UPDATE focus SET
                username = :username,
                firstname = :firstname,
                lastname = :lastname,
                email = :email,
                password = :password,
            WHERE
                id = :id';
        return $this->execute($sql, $foc);
    }

    /**
     * @return Foc
     * @throws Exception
     */
    private function execute($sql, Foc $foc) {
        $statement = $this->getDb()->prepare($sql);
        $this->executeStatement($statement, $this->getParams($foc));
        if (!$foc->getId()) {
            return $this->findById($this->getDb()->lastInsertId());
        }
        if (!$statement->rowCount()) {
            throw new NotFoundException('FOC with ID "' . $foc->getId() . '" does not exist.');
        }
        return $foc;
    }

    private function getParams(Foc $foc) {
        $params = array(
            ':id' => $foc->getId(),
            ':username' => $foc->getUsername(),
            ':firstname' => $foc->getFirstname(),
            ':lastname' => $foc->getLastname(),
            ':email' => $foc->getEmail(),
            ':password' => $foc->getPassword()
        );
        if ($foc->getId()) {
            // unset created date, this one is never updated
            unset($params[':created_on']);
        }
        return $params;
    }

    private function executeStatement(PDOStatement $statement, array $params) {
        if (!$statement->execute($params)) {
            self::throwDbError($this->getDb()->errorInfo());
        }
    }

    /**
     * @return PDOStatement
     */
    private function query($sql) {
        $statement = $this->getDb()->query($sql, PDO::FETCH_ASSOC);
        if ($statement === false) {
            self::throwDbError($this->getDb()->errorInfo());
        }
        return $statement;
    }

    private static function throwDbError(array $errorInfo) {
        // log error, send email, etc.
        throw new Exception('DB error [' . $errorInfo[0] . ', ' . $errorInfo[1] . ']: ' . $errorInfo[2]);
    }

    private static function formatDateTime(DateTime $date) {
        return $date->format(DateTime::ISO8601);
    }

}
