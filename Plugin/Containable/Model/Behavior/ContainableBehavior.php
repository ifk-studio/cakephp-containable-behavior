<?php
/**
 * ORM hack
 *
 * @author      Dmitry Lyapin <dma@cranbee.com>
 * @package     Cake.Model.Behavior
 */

App::uses('ModelBehavior', 'Model');

/**
 * @package     Cake.Model.Behavior
 */
class ContainableBehavior extends ModelBehavior {

	/**
	 * Types of relationships available for models
	 *
	 * @var array
	 */
	public $types = array('belongsTo', 'hasOne', 'hasMany', 'hasAndBelongsToMany');

	/**
	 * Runtime configuration for this behavior
	 *
	 * @var array
	 */
	public $runtime = array();

	/**
	 * Stack of contains from beforeFind()
	 *
	 * @var array
	 */
	private $containStack = array();

	/**
	 * Cache
	 *
	 * @var array
	 */
	private $cache = array();

	/**
	 * Runs before a find() operation.
	 *
	 * @param Model $model Model using the behavior
	 * @param array $query Query parameters as set by cake
	 *
	 * @return array
	 */
	public function beforeFind(Model $model, $query) {
		$contain = isset($query['contain'])
			? $this->_normalizeContain($query['contain'])
			: array();

		$this->containStack[] = $contain;

		if (!$contain) {
			return $query;
		}

		$query['recursive'] = -1;
		$fields = array("{$model->alias}.*");

		foreach ($contain as $k => $v) {
			$relation = $this->_getRelation($model, $k);

			switch ($relation) {
				case 'belongsTo':
				case 'hasOne':
					$relModel = $model->{$k};
					$fk = $model->{$relation}[$k]['foreignKey'];
					$conditions = $model->{$relation}[$k]['conditions'];
					$conditions = $conditions ? : array();
					if ($fk) {
						$conditions[] = ($relation == 'belongsTo')
							? "\"{$model->alias}\".\"{$fk}\" = \"{$relModel->alias}\".\"{$relModel->primaryKey}\"" // belongsTo
							: "\"{$model->alias}\".\"{$model->primaryKey}\" = \"{$relModel->alias}\".\"{$fk}\""; // hasOne
					}
					$query['joins'][] = array(
						'table' => $relModel->table,
						'alias' => $relModel->alias,
						'type' => 'LEFT',
						'conditions' => $conditions
					);
					$fields[] = "{$relModel->alias}.*";
					break;
			}
		}

		if (!$query['fields']) {
			$query['fields'] = $fields;
		}

		return $query;
	}

	/**
	 * After find callback, used to modify results returned by find.
	 *
	 * @param Model   $model   Model using this behavior
	 * @param mixed   $results The results of the find operation
	 * @param boolean $primary Whether this model is being queried directly (vs. being queried as an association)
	 *
	 * @return mixed An array value will replace the value of $results - any other value will be ignored.
	 */
	public function afterFind(Model $model, $results, $primary = false) {
		$contain = array_pop($this->containStack);

		if (!$contain) {
			return $results;
		}

		if (!isset($results[0][$model->alias])) {
			return $results;
		}

		$newResults = array();
		$this->cache = array();
		$ids = array();

		foreach ($results as $item) {
			$ids[] = $item[$model->alias][$model->primaryKey];
		}

		foreach ($results as $item) {
			$newItem = array();
			$newItem[$model->alias] = $item[$model->alias];

			if (isset($item[0])) {
				$newItem[0] = $item[0];
			}

			foreach ($contain as $k => $v) {
				$relation = $this->_getRelation($model, $k);

				switch ($relation) {
					case 'belongsTo':
					case 'hasOne':
						$newItem[$k] = $this->_extend($model->{$k}, $v, $item[$k]);
						break;

					case 'hasMany':
					case 'hasAndBelongsToMany':
						$methodName = '_fetch' . ucfirst($relation);
						$innerItems = $this->{$methodName}($model, $k, $item[$model->alias], $ids);
						$n = count($innerItems);
						for ($i = 0; $i < $n; $i++) {
							$innerItems[$i] = $this->_extend($model->{$k}, $v, $innerItems[$i]);
						}
						$newItem[$k] = $innerItems;
						break;
				}
			}

			$newResults[] = $newItem;
		}

		$this->cache = [];
		return $newResults;
	}

	/**
	 * Unbinds all relations from a model except the specified ones. Calling this function without
	 * parameters unbinds all related models.
	 *
	 * @param Model $Model Model on which binding restriction is being applied
	 *
	 * @return void
	 * @link http://book.cakephp.org/2.0/en/core-libraries/behaviors/containable.html#using-containable
	 */
	public function contain(Model $Model) {
		$args = func_get_args();
		$contain = call_user_func_array('am', array_slice($args, 1));
		$this->runtime[$Model->alias]['contain'] = $contain;
	}


	/**
	 * @param Model $model
	 * @param array $contain
	 * @param array $item
	 *
	 * @return mixed
	 */
	private function _extend(Model $model, $contain, $item) {
		$newItem = $item;

		foreach ($contain as $k => $v) {
			$relation = $this->_getRelation($model, $k);

			switch ($relation) {
				case 'belongsTo':
				case 'hasOne':
					$innerItem = $this->_fetchBelongsToOrHasOne($relation, $model, $k, $item);
					if ($innerItem) {
						$innerItem = $this->_extend($model->{$k}, $v, $innerItem);
					}
					$newItem[$k] = $innerItem;
					break;

				case 'hasMany':
				case 'hasAndBelongsToMany':
					$methodName = '_fetch' . ucfirst($relation);
					$innerItems = $this->{$methodName}($model, $k, $item);
					$n = count($innerItems);
					for ($i = 0; $i < $n; $i++) {
						$innerItems[$i] = $this->_extend($model->{$k}, $v, $innerItems[$i]);
					}
					$newItem[$k] = $innerItems;
					break;
			}
		}

		return $newItem;
	}

	/**
	 * @param Model  $model
	 * @param string $to
	 *
	 * @return null|string
	 */
	private function _getRelation(Model $model, $to) {
		if (isset($model->belongsTo[$to])) {
			return 'belongsTo';
		}
		if (isset($model->hasOne[$to])) {
			return 'hasOne';
		}
		if (isset($model->hasMany[$to])) {
			return 'hasMany';
		}
		if (isset($model->hasAndBelongsToMany[$to])) {
			return 'hasAndBelongsToMany';
		}
		return null;
	}

	/**
	 * @param string $relation
	 * @param Model  $m1
	 * @param string $to
	 * @param array  $row
	 *
	 * @return mixed
	 */
	private function _fetchBelongsToOrHasOne($relation, $m1, $to, $row) {
		$m2 = $m1->{$to};
		$fk = $m1->{$relation}[$to]['foreignKey'];
		$conditions = $this->_normalizeConditions($m1->{$relation}[$to]['conditions']);

		if ($fk) {
			$conditions[] = ($relation == 'belongsTo')
				? array("\"{$m2->alias}\".\"{$m2->primaryKey}\" = ?", $row[$fk]) // belongsTo
				: array("\"{$m2->alias}\".\"{$fk}\" = ?", $row[$m1->primaryKey]); // hasOne
		}

		list($where, $whereParams) = $this->_generateWhere($conditions);
		$pdo = $m2->getDataSource()->getConnection();
		$stm = $pdo->prepare("SELECT * FROM \"{$m2->table}\" AS \"{$m2->alias}\" WHERE {$where}");
		$stm->execute($whereParams);
		return $stm->fetch(PDO::FETCH_ASSOC);
	}

	/**
	 * @param Model      $m1
	 * @param string     $to
	 * @param array      $row
	 * @param array|null $ids
	 *
	 * @return array
	 */
	private function _fetchHasMany(Model $m1, $to, $row, $ids = null) {
		$id = $row[$m1->primaryKey];
		$m2 = $m1->{$to};
		$fk = $m1->hasMany[$to]['foreignKey'];
		$conditions = $this->_normalizeConditions($m1->hasMany[$to]['conditions']);
		$order = $this->_normalizeOrder($m1->hasMany[$to]['order']);
		$pdo = $m2->getDataSource()->getConnection();

		if (!$ids || $conditions) {
			if ($fk) {
				$conditions[] = array("\"{$m2->alias}\".\"{$fk}\" = ?", $id);
			}
			list($where, $whereParams) = $this->_generateWhere($conditions);
			$sql = "SELECT * FROM \"{$m2->table}\" AS \"{$m2->alias}\" WHERE {$where}";
			if ($order) {
				$sql .= " ORDER BY {$order}";
			}
			$stm = $pdo->prepare($sql);
			$stm->execute($whereParams);
			return $stm->fetchAll(PDO::FETCH_ASSOC);
		}

		$sIds = implode(',', $ids);
		$cacheKey = "_fetchHasMany:{$m1->name}:{$to}:{$sIds}";

		if (isset($this->cache[$cacheKey])) {
			$all = $this->cache[$cacheKey];
		} else {
			$sql = "SELECT * FROM \"{$m2->table}\" AS \"{$m2->alias}\" WHERE \"{$m2->alias}\".\"{$fk}\" IN ({$sIds})";
			if ($order) {
				$sql .= " ORDER BY {$order}";
			}
			$stm = $pdo->prepare($sql);
			$stm->execute();
			$all = $stm->fetchAll(PDO::FETCH_ASSOC);
			$this->cache[$cacheKey] = $all;
		}

		$result = array();

		foreach ($all as $item) {
			if ($item[$fk] == $id) {
				$result[] = $item;
			}
		}

		return $result;
	}

	/**
	 * @param Model  $m1
	 * @param string $to
	 * @param array  $row
	 *
	 * @return mixed
	 */
	private function _fetchHasAndBelongsToMany(Model $m1, $to, $row) {
		$id = $row[$m1->primaryKey];
		$joinTable = $m1->hasAndBelongsToMany[$to]['joinTable'];
		$fk = $m1->hasAndBelongsToMany[$to]['foreignKey'];
		$afk = $m1->hasAndBelongsToMany[$to]['associationForeignKey'];
		$m2 = $m1->{$to};
		$pdo = $m2->getDataSource()->getConnection();
		$stm = $pdo->prepare("SELECT b.* FROM \"{$m2->table}\" b JOIN \"{$joinTable}\" axb ON axb.\"{$afk}\" = b.\"{$m2->primaryKey}\" WHERE axb.\"{$fk}\" = ?");
		$stm->execute(array($id));
		return $stm->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * @param array|string $contain
	 *
	 * @return array
	 */
	private function _normalizeContain($contain) {
		if (!$contain) {
			return array();
		}

		if (!is_array($contain)) {
			$contain = array($contain);
		}

		$result = array();

		foreach ($contain as $k => $v) {

			if (is_numeric($k)) {
				$name = $v;
				$innerContain = array();
			} else {
				$name = $k;
				$innerContain = $this->_normalizeContain($v);
			}

			$nameParts = explode('.', $name);
			$result[$nameParts[0]] = $innerContain;
		}

		return $result;
	}

	/**
	 * @param array $conditions
	 *
	 * @return array
	 */
	private function _normalizeConditions($conditions) {
		if (!$conditions) {
			return array();
		}

		$result = array();

		foreach ($conditions as $k => $v) {
			if (is_numeric($k)) {
				$result[] = array($v);
			} else {
				$parts = explode('.', $k);
				$result[] = array("\"{$parts[0]}\".\"{$parts[1]}\" = ?", $v);
			}
		}

		return $result;
	}

	/**
	 * @param $array |string order
	 *
	 * @return null|string
	 */
	private function _normalizeOrder($order) {
		if (!$order) {
			return null;
		}

		$result = array();

		if (!is_array($order)) {
			$order = array($order);
		}

		foreach ($order as $k => $v) {
			if (is_numeric($k)) {
				$part = $v;
			} else {
				$part = "{$k} {$v}";
			}

			$result[] = preg_replace(
				'/^([a-z0-9]+)\.([a-z0-9]+)\s+([a-z]+)$/i',
				'"$1"."$2" $3',
				$part
			);
		}

		return implode(',', $result);
	}

	/**
	 * @param array $conditions
	 *
	 * @return array
	 */
	private function _generateWhere($conditions) {
		$where = '1 = 1';
		$params = array();

		foreach ($conditions as $c) {
			$where .= " AND {$c[0]}";
			if (isset($c[1])) {
				$params[] = $c[1];
			}
		}

		return array($where, $params);
	}
}
