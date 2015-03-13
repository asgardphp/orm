<?php
namespace Asgard\Orm;

/**
 * Helps performing operations like selection, deletion and update on a set of entities.
 * @author Michel Hognerud <michel@hognerud.net>
*/
class ORM implements ORMInterface {
	/**
	 * DataMapper instance.
	 * @var DataMapperInterface
	 */
	protected $dataMapper;
	/**
	 * Entity definition.
	 * @var \Asgard\Entity\Definition
	 */
	protected $definition;
	/**
	 * Eager-loaded relations.
	 * @var array
	 */
	protected $with;
	/**
	 * Conditions.
	 * @var array
	 */
	protected $where = [];
	/**
	 * OrderBy.
	 * @var string
	 */
	protected $orderBy;
	/**
	 * Limit.
	 * @var integer
	 */
	protected $limit;
	/**
	 * Offset.
	 * @var integer
	 */
	protected $offset;
	/**
	 * Joined relations.
	 * @var array
	 */
	protected $join = [];
	/**
	 * Page number.
	 * @var integer
	 */
	protected $page;
	/**
	 * Number of elements per page.
	 * @var integer
	 */
	protected $per_page;
	/**
	 * Default locale.
	 * @var string
	 */
	protected $locale;
	/**
	 * Tables prefix.
	 * @var string
	 */
	protected $prefix;
	/**
	 * Ongoing DAL instance.
	 * @var \Asgard\Db\DAL
	 */
	protected $tmp_dal = null;
	/**
	 * Paginator factory.
	 * @var \Asgard\Common\PaginatorFactoryInterface
	 */
	protected $paginatorFactory = null;

	/**
	 * Constructor.
	 * @param \Asgard\Entity\Definition $definition
	 * @param DataMapperInterface             $datamapper
	 * @param string                          $locale      default locale
	 * @param string                          $prefix      tables prefix
	 * @param \Asgard\Common\PaginatorFactoryInterface   $paginatorFactory
	 */
	public function __construct(\Asgard\Entity\Definition $definition, DataMapperInterface $datamapper, $locale=null, $prefix=null, \Asgard\Common\PaginatorFactoryInterface $paginatorFactory=null) {
		$this->definition       = $definition;
		$this->dataMapper       = $datamapper;
		if($locale !== null)
			$this->locale = $locale;
		else
			$this->locale = $definition->getEntityManager()->getDefaultLocale();
		$this->prefix           = $prefix;
		$this->paginatorFactory = $paginatorFactory;

		if($this->definition->get('order_by'))
			$this->orderBy($this->definition->get('order_by'));
		else
			$this->orderBy('id DESC');
	}

	/**
	 * Return the definition.
	 * @return \Asgard\Entity\Definition
	 */
	public function getDefinition() {
		return $this->definition;
	}

	/**
	 * {@inheritDoc}
	*/
	public function __call($relationName, array $args) {
		if(!$this->dataMapper->hasRelation($this->definition, $relationName))
			throw new \Exception('Relation '.$relationName.' does not exist.');
		$relation = $this->dataMapper->relation($this->definition, $relationName);
		$reverseRelation = $relation->reverse();
		$reverseRelationName = $reverseRelation->get('name');
		$relation_entity = $relation->get('entity');

		$table = $this->getTable();
		$alias = $reverseRelationName;

		$where = $this->updateConditions($this->where, $table, $alias);

		return $this->dataMapper->orm($relation_entity)
			->where($where)
			->join($reverseRelationName, $this->join);
	}

	/**
	 * Replace the old table with the new alias.
	 * @param  array  $conditions
	 * @return array
	 */
	protected function updateConditions(array $conditions, $table, $alias) {
		$res = [];

		foreach($conditions as $k=>$v) {
			if(is_array($v))
				$v = $this->updateConditions($v, $table, $alias);
			$k = preg_replace('/(?<![\.a-zA-Z0-9-_`\(\)])'.$table.'\./', $alias.'.', $k);
			$res[$k] = $v;
		}

		return $res;
	}

	/**
	 * {@inheritDoc}
	*/
	public function __get($name) {
		return $this->$name()->get();
	}

	protected function getNewAlias($name, array $existing) {
		$i=1;
		$alias = $name;
		while(in_array($alias, $existing))
			$alias = $name.$i++;
		return $alias;
	}

	/**
	 * {@inheritDoc}
	*/
	public function joinToEntity($relation, \Asgard\Entity\Entity $entity) {
		if(is_string($relation))
			$relation = $this->dataMapper->relation($this->definition, $relation);

		$relationName = $relation->getName();
		$alias = $this->getNewAlias($relationName, $this->getAliases($this->join));

		if($alias !== $relationName)
			$relationName .= ' '.$alias;

		$this->where($alias.'.id', $entity->id);
		$this->join($relationName);

		return $this;
	}

	protected function getAliases(array $jointures) {
		$aliases = [];
		foreach($jointures as $name=>$subjointures) {
			if(is_array($subjointures)) {
				if(!is_numeric($name)) {
					$exp = explode(' ', $name);
					$alias = count($exp) > 1 ? $exp[1]:$exp[0];
					$aliases[] = $alias;
				}
				$aliases = array_merge($aliases, $this->getAliases($subjointures));
			}
			else {
				$name = $subjointures;
				$exp = explode(' ', $name);
				$alias = count($exp) > 1 ? $exp[1]:$exp[0];
				$aliases[] = $alias;
			}
		}
		return $aliases;
	}

	public function setJointuresAliases($jointures, array $existing=[]) {
		if(is_array($jointures)) {
			foreach($clone=$jointures as $k=>$v) {
				$name = $k;
				if(!is_numeric($name)) {
					$exp = explode(' ', $name);
					$origName = $exp[0];
					$oldAlias = count($exp) > 1 ? $exp[1]:$exp[0];

					$alias = $this->getNewAlias($oldAlias, $existing);

					if($alias !== $oldAlias) {
						$name = $origName.' '.$alias;
						unset($jointures[$k]);
						$this->where = $this->updateConditions($this->where, $oldAlias, $alias);#replace old name in conditions
					}

					$existing[] = $alias;
				}

				$jointures[$name] = $newJointures = $this->setJointuresAliases($v, $existing);
				$newJointures = (array)$newJointures;
				$existing = array_merge($existing, $this->getAliases($newJointures)); #add new aliases
			}
		}
		else {
			$name = $jointures;
			$exp = explode(' ', $name);
			$origName = $exp[0];
			$oldAlias = count($exp) > 1 ? $exp[1]:$exp[0];

			$alias = $this->getNewAlias($oldAlias, $existing);
			if($alias !== $oldAlias) {
				$name = $origName.' '.$alias;
				$this->where = $this->updateConditions($this->where, $oldAlias, $alias);#replace old name in conditions
			}
			return $name;
		}

		return $jointures;
	}

	/**
	 * {@inheritDoc}
	*/
	public function join($relation, array $subrelations=null) {
		$aliases = array_merge([$this->getTable()], $this->getAliases($this->join));
		
		if($subrelations) {
			$alias = $relation;
			$i=1;
			while(in_array($alias, $aliases))
				$alias = $relation.$i++;
			$aliases[] = $alias;
			if($alias !== $relation)
				$relation .= ' '.$alias;
			$subrelations = $this->setJointuresAliases($subrelations, $aliases);
			$this->join[$relation] = $subrelations;
		}
		else {
			$relations = $this->setJointuresAliases($relation, $aliases);
			$this->join[] = $relations;
		}

		return $this;
	}

	/**
	 * {@inheritDoc}
	*/
	public function getTable() {
		return $this->dataMapper->getTable($this->definition);
	}

	/**
	 * {@inheritDoc}
	*/
	public function getTranslationTable() {
		return $this->dataMapper->getTranslationTable($this->definition);
	}

	/**
	 * Converts a raw array to an entity.
	 *
	 * @param array                     $raw
	 * @param \Asgard\Entity\Definition $definition The definition of the entity to be instantiated.
	 *
	 * @return \Asgard\Entity\Entity
	*/
	protected function toEntity(array $raw, \Asgard\Entity\Definition $definition=null) {
		if(!$definition)
			$definition = $this->definition;
		$new = $definition->make([], $this->locale);
		return static::unserialize($new, $raw);
	}

	/**
	 * Fills up an entity with a raw array of data.
	 *
	 * @param \Asgard\Entity\Entity $entity
	 * @param array                 $data
	 * @param string                $locale Only necessary if the data concerns a specific locale.
	 *
	 * @return \Asgard\Entity\Entity
	*/
	protected static function unserialize(\Asgard\Entity\Entity $entity, array $data, $locale=null) {
		foreach($data as $k=>$v) {
			if($entity->getDefinition()->hasProperty($k))
				$data[$k] = $entity->getDefinition()->property($k)->unserialize($v, $entity, $k);
		}

		return $entity->_set($data, $locale);
	}

	/**
	 * {@inheritDoc}
	*/
	public function next() {
		if(!$this->tmp_dal)
			$this->tmp_dal = $this->getDAL();
		if(!($r = $this->tmp_dal->next()))
			return null;
		else
			return $this->toEntity($r);
	}

	/**
	 * {@inheritDoc}
	*/
	public function ids() {
		return $this->values('id');
	}

	/**
	 * {@inheritDoc}
	*/
	public function values($property) {
		$res = [];
		while($one = $this->next())
			$res[] = $one->get($property);
		return $res;
	}

	/**
	 * {@inheritDoc}
	*/
	public function first() {
		$res = $this->limit(1)->get();
		if(!count($res))
			return null;
		return $res[0];
	}

	/**
	 * {@inheritDoc}
	*/
	public function all() {
		return $this->get();
	}

	/**
	 * {@inheritDoc}
	*/
	public function getDAL() {
		$dal = new \Asgard\Db\DAL($this->dataMapper->getDB());
		$table = $this->getTable();
		$dal->orderBy($this->orderBy);
		$dal->limit($this->limit);
		$dal->offset($this->offset);
		$dal->groupBy($table.'.id');

		$dal->where($this->processConditions($this->where));

		if($this->definition->isI18N()) {
			$translation_table = $this->getTranslationTable();
			$selects = [$table.'.*'];
			foreach($this->definition->properties() as $name=>$property) {
				if($property->get('i18n'))
					$selects[] = $translation_table.'.'.$name;
			}
			$dal->select($selects);
			$dal->from($table);
			$dal->leftjoin([
				$translation_table => $this->processConditions([
					$table.'.id = '.$translation_table.'.id',
					$translation_table.'.locale' => $this->locale
				])
			]);
		}
		else {
			$dal->select([$table.'.*']);
			$dal->from($table);
		}

		$this->recursiveJointures($dal, $this->join, $this->definition, $this->getTable());

		return $dal;
	}

	/**
	 * Performs jointures on the DAL object.
	 *
	 * @param \Asgard\Db\DAL            $dal
	 * @param array                     $jointures Array of relations.
	 * @param \Asgard\Entity\Definition $definition The entity class from which jointures are built.
	 * @param string                    $table The table from which to performs jointures.
	*/
	protected function recursiveJointures(\Asgard\Db\DAL $dal, $jointures, \Asgard\Entity\Definition $definition, $table) {
		$alias = null;
		if(is_array($jointures)) {
			foreach($jointures as $k=>$v) {
				if(!is_numeric($k)) {
					$relationName = $k;
					if(strpos($relationName, ' '))
						list($relationName, $alias) = explode(' ', $relationName);
					else
						$alias = null;
					$relation = $this->dataMapper->relation($definition, $relationName);
					$this->jointure($dal, $relation, $alias, $table);

					$tableAlias = $alias ? $alias:$relationName;

					$this->recursiveJointures($dal, $v, $relation->getTargetDefinition(), $tableAlias);
				}
				else
					$this->recursiveJointures($dal, $v, $definition, $table);
			}
		}
		else {
			$relation = $jointures;
			if(strpos($relation, ' '))
				list($relation, $alias) = explode(' ', $relation);
			$relation = $this->dataMapper->relation($definition, $relation);
			$this->jointure($dal, $relation, $alias, $table);
		}
	}

	/**
	 * Performs a single jointure.
	 *
	 * @param \Asgard\Db\DAL $dal
	 * @param EntityRelation $relation The name of the relation.
	 * @param string         $alias How the related table will be referenced in the SQL query.
	 * @param string         $ref_table The table from which to performs the jointure.
	*/
	protected function jointure(\Asgard\Db\DAL $dal, $relation, $alias, $ref_table) {
		$relationName = $relation->getName();

		$relationDefinition = $relation->getTargetDefinition();
		if($alias === null)
			$alias = $relationName;

		switch($relation->type()) {
			case 'hasOne':
			case 'belongsTo':
				$link = $relation->getLink();
				$table = $this->dataMapper->getTable($relationDefinition);
				$dal->innerjoin([
					$table.' '.$alias => $this->processConditions([
						$alias.'.id = '.$ref_table.'.'.$link
					])
				]);
				break;
			case 'hasMany':
				$link = $relation->getLink();
				$table = $this->dataMapper->getTable($relationDefinition);
				if($relation->isPolymorphic()) {
					$dal->innerjoin([
						$table.' '.$alias => $this->processConditions([
							$alias.'.'.$link.' = '.$ref_table.'.id',
						])
					]);
				}
				else {
					$dal->innerjoin([
						$table.' '.$alias => $this->processConditions([
							$alias.'.'.$link.' = '.$ref_table.'.id',
						])
					]);
				}
				break;
			case 'HMABT':
				if($relation->isPolymorphic()) {
					$dal->innerjoin([
						$relation->getAssociationTable($this->prefix) => $this->processConditions([
							$relation->getAssociationTable($this->prefix).'.'.$relation->getLinkA().' = '.$ref_table.'.id',
							$relation->getAssociationTable($this->prefix).'.'.$relation->getLinkType() => $relation->getTargetDefinition()->getClass(),
						])
					]);
				}
				else {
					$dal->innerjoin([
						$relation->getAssociationTable($this->prefix) => $this->processConditions([
							$relation->getAssociationTable($this->prefix).'.'.$relation->getLinkA().' = '.$ref_table.'.id',
						])
					]);
				}
				$dal->innerjoin([
					$this->dataMapper->getTable($relationDefinition).' '.$alias => $this->processConditions([
						$relation->getAssociationTable($this->prefix).'.'.$relation->getLinkB().' = '.$alias.'.id',
					])
				]);
				if($relation->reverse()->get('sortable'))
					$dal->orderBy($relation->getAssociationTable($this->prefix).'.'.$relation->reverse()->getPositionField().' ASC');
				break;
		}

		if($relationDefinition->isI18N()) {
			$translation_table = $this->dataMapper->getTranslationTable($relationDefinition);
			$dal->leftjoin([
				$translation_table.' '.$relationName.'_translation' => $this->processConditions([
					$ref_table.'.id = '.$relationName.'_translation.id',
					$relationName.'_translation.locale' => $this->locale
				])
			]);
		}
	}

	/**
	 * {@inheritDoc}
	*/
	public function get() {
		$entities = [];
		$ids = [];

		$dal = $this->getDAL();

		$rows = $dal->get();
		foreach($rows as $row) {
			if(!$row['id'])
				continue;
			$entities[] = $this->toEntity($row);
			$ids[] = $row['id'];
		}

		if(count($entities) && count($this->with)) {
			foreach($this->with as $relationName=>$closure) {
				$rel = $this->dataMapper->relation($this->definition, $relationName);
				$relation_type = $rel->type();
				$relation_entity = $rel->get('entity');
				$relationDefinition = $rel->getTargetDefinition();

				switch($relation_type) {
					case 'hasOne':
					case 'belongsTo':
						$link = $rel->getLink();

						$orm = $this->dataMapper->orm($relation_entity)->where(['id IN ('.implode(', ', $ids).')']);
						if(is_callable($closure))
							$closure($orm);
						$res = $orm->get();
						foreach($entities as $entity) {
							$id = $entity->$link;
							$filter = array_filter($res, function($result) use($id) {
								return ($id == $result->id);
							});
							$filter = array_values($filter);
							if(isset($filter[0]))
								$entity->$relationName = $filter[0];
							else
								$entity->$relationName = null;
						}
						break;
					case 'hasMany':
						$link = $rel->getLink();

						$orm = $this->dataMapper->orm($relation_entity)->where([$link.' IN ('.implode(', ', $ids).')']);
						if(is_callable($closure))
							$closure($orm);
						$res = $orm->get();
						foreach($entities as $entity) {
							$id = $entity->id;
							$filter = array_filter($res, function($result) use($id, $link) {
								return ($id == $result->$link);
							});
							$filter = array_values($filter);
							$entity->$relationName = $filter;
						}
						break;
					case 'HMABT':
						$joinTable = $rel->getAssociationTable();
						$currentEntityIdfield = $rel->getLinkA();
						$reverseRelationName = $rel->reverse()->get('name');

						$orm = $this->dataMapper
							->orm($relation_entity)
							->join($reverseRelationName)
							->where([
								$this->getTable().'.id IN ('.implode(', ', $ids).')',
							]);

						if(is_callable($closure))
							$closure($orm);
						$res = $orm->getDAL()->addSelect($joinTable.'.'.$currentEntityIdfield.' as __ormrelid')->groupBy(null)->get();
						foreach($entities as $entity) {
							$id = $entity->id;
							$filter = array_filter($res, function($result) use ($id) {
								return $id == $result['__ormrelid'];
							});
							$filter = array_values($filter);
							$mres = [];
							foreach($filter as $m)
								$mres[] = $this->toEntity($m, $relationDefinition);
							$entity->$relationName = $mres;
						}
						break;
					default:
						throw new \Exception('Relation type '.$relation_type.' does not exist');
				}
			}
		}

		return $entities;
	}

	/**
	 * {@inheritDoc}
	*/
	public function selectQuery($sql, array $args=[]) {
		$entities = [];

		$dal = new \Asgard\Db\DAL($this->dataMapper->getDB());
		$rows = $dal->query($sql, $args)->all();
		foreach($rows as $row)
			$entities[] = static::unserialize($this->definition->make(), $row);

		return $entities;
	}

	/**
	 * {@inheritDoc}
	*/
	public function paginate($page=1, $per_page=10) {
		$this->page = $page;
		$this->per_page = $per_page;
		$this->offset(($this->page-1)*$this->per_page);
		$this->limit($this->per_page);

		return $this;
	}

	/**
	 * {@inheritDoc}
	*/
	public function getPaginator() {
		$page = $this->page !== null ? $this->page : 1;
		$per_page = $this->per_page !== null ? $this->per_page : 10;

		if($this->paginatorFactory)
			return $this->paginatorFactory->create($this->count(), $page, $per_page);
		else
			return new \Asgard\Common\Paginator($this->count(), $page, $per_page);
	}

	/**
	 * {@inheritDoc}
	*/
	public function with($with, \Closure $closure=null) {
		$this->with[$with] = $closure;
		return $this;
	}

	/**
	 * Set the tables.
	 *
	 * @param string $sql SQL query.
	 *
	 * @return string The modified SQL query.
	*/
	protected function replaceTable($sql) {
		$table = $this->getTable();
		$i18nTable = $this->getTranslationTable();
		preg_match_all('/(?<![\.a-zA-Z0-9-_`\(\)])([a-z_][a-zA-Z0-9-_]*)(?![\.`\(\)])/', $sql, $matches);
		foreach($matches[0] as $property) {
			if($this->definition->hasProperty($property))
				$table = $this->definition->property($property)->get('i18n') ? $i18nTable:$table;
			$sql = preg_replace('/(?<![\.a-zA-Z0-9-_`\(\)])('.$property.')(?![\.a-zA-Z0-9-_`\(\)])/', $table.'.$1', $sql);
		}

		return $sql;
	}

	/**
	 * Format the conditions before being used in SQL.
	 *
	 * @param array $conditions
	 *
	 * @return array FormInterfaceatted conditions.
	*/
	protected function processConditions(array $conditions) {
		foreach($conditions as $k=>$v) {
			if(!is_array($v)) {
				if(is_numeric($k))
					$newK = $k;
				else
					$newK = $this->replaceTable($k);
				$conditions[$newK] = $v;
				if($newK != $k)
					unset($conditions[$k]);
			}
			else
				$conditions[$k] = $this->processConditions($conditions[$k]);
		}

		return $conditions;
	}

	/**
	 * {@inheritDoc}
	*/
	public function where($conditions, $val=null) {
		if(!$conditions)
			return $this;
		if($val === null) {
			if(!is_array($conditions))
				$conditions = [$conditions];
			$this->where[] = $this->processConditions($conditions);
		}
		else
			$this->where[] = $this->processConditions([$conditions=>$val]);

		return $this;
	}

	/**
	 * {@inheritDoc}
	*/
	public function offset($offset) {
		$this->offset = $offset;
		return $this;
	}

	/**
	 * {@inheritDoc}
	*/
	public function limit($limit) {
		$this->limit = $limit;
		return $this;
	}

	/**
	 * {@inheritDoc}
	*/
	public function orderBy($orderBy) {
		$this->orderBy = $orderBy;
		return $this;
	}

	/**
	 * {@inheritDoc}
	*/
	public function delete() {
		$count = 0;
		while($entity = $this->next())
			$count += $this->dataMapper->destroy($entity);

		return $count;
	}

	/**
	 * {@inheritDoc}
	*/
	public function update(array $values) {
		while($entity = $this->next())
			$this->dataMapper->save($entity, $values);

		return $this;
	}

	/**
	 * {@inheritDoc}
	*/
	public function count($group_by=null) {
		return $this->getDAL()->count('DISTINCT '.$this->getTable().'.id', $group_by);
	}

	/**
	 * {@inheritDoc}
	*/
	public function min($what, $group_by=null) {
		return $this->getDAL()->min($what, $group_by);
	}

	/**
	 * {@inheritDoc}
	*/
	public function max($what, $group_by=null) {
		return $this->getDAL()->max($what, $group_by);
	}

	/**
	 * {@inheritDoc}
	*/
	public function avg($what, $group_by=null) {
		return $this->getDAL()->avg($what, $group_by);
	}

	/**
	 * {@inheritDoc}
	*/
	public function sum($what, $group_by=null) {
		return $this->getDAL()->sum($what, $group_by);
	}

	/**
	 * {@inheritDoc}
	*/
	public function reset() {
		$this->where   = [];
		$this->with    = [];
		$this->orderBy = null;
		$this->limit   = null;
		$this->offset  = null;
		$this->join    = [];

		return $this;
	}
}