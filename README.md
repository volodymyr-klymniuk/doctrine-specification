# Doctrine Specification

This library gives you a new way for writing queries. Using the [Specification pattern][wiki_spec_pattern] you will
get small Specification classes that are highly reusable.

The problem with writing Doctrine queries is that it soon will be messy. When your application grows you will have
20+ function in your Doctrine repositories. All with long and complicated QueryBuilder calls. You will also find that
you are using a lot of parameters to the same method to accommodate different use cases.

After a discussion with Kacper Gunia on [Sound of Symfony podcast][sos] about how to test your Doctrine repositories properly, we (Kacper and Tobias) decided to create this library. We have been inspired by Benjamin Eberlei's thoughts in his [blog post][whitewashing].

### Table of contents

1. [Motivation](#why-do-we-need-this-lib) and [basic understanding](#the-practical-differences) (this page)
2. [Usage examples][doc-usage]
3. [Create your own spec][doc-create]
4. [Contributing to the library][contributing]


## Why do we need this lib?

You are probably wondering why we created this library. Your entity repositories are working just fine as they are, right?

But if your friend open one of your repository classes he/she would probably find that the code is not as perfect as you thought.
Entity repositories have a tendency to get messy. Problems may include:

* Too many functions (`findActiveUser`, `findActiveUserWithPicture`, `findUserToEmail`, etc)
* Some functions have too many arguments
* Code duplication
* Difficult to test

## Requirements of the solution

The solution should have the following features:

* Easy to test
* Easy to extend, store and run
* Re-usable code
* Single responsibility principle
* Hides the implementation details of the ORM. (This might seen like nitpicking, however it leads to bloated client code
  doing the query builder work over and over again.)

## The practical differences

This is an example of how you use the lib. Say that you want to fetch some Adverts and close them. We should select all Adverts that have their `endDate` in the past. If `endDate` is null make it 4 weeks after the `startDate`.

```php
// Not using the lib
$qb = $this->em->getRepository('User')
    ->createQueryBuilder('r');

return $qb->where('r.ended = 0')
    ->andWhere(
        $qb->expr()->orX(
            'r.endDate < :now',
            $qb->expr()->andX(
                'r.endDate IS NULL',
                'r.startDate < :timeLimit'
            )
        )
    )
    ->setParameter('now', new \DateTime())
    ->setParameter('timeLimit', new \DateTime('-4weeks'))
    ->getQuery()
    ->getResult();
```

```php
use VolodymyrKlymniuk\DoctrineSpecification\Specification;

// Using the lib
$spec = Specification::expr()->andX(
    Specification::expr()->eq('ended', 0),
    Specification::expr()->orX(
        Specification::expr()->lt('endDate', new \DateTime()),
        Specification::expr()->andX(
            Specification::expr()->isNull('endDate'),
            Specification::expr()->lt('startDate', new \DateTime('-4weeks'))
        )
    )
);

return $this->em->getRepository('RecruitmentBundle:Advert')->match($spec);
```

Yes, it looks pretty much the same. But the later is reusable. Say you want another query to fetch Adverts that we
should close but only for a specific company.

#### Doctrine Specification

```php
/**
 * Class AdvertsWeShouldCloseSpecification
 */
class AdvertsWeShouldCloseSpecification extends Specification
{
    /**
     * {@inheritdoc}
     */
    public static function create()
    {
        return new self();
    }

    /**
     * @param ExpressionInterface $spec
     *
     * @return $this
     */
    public function applyWhere(ExpressionInterface $spec)
    {
        $this->andWhere($spec);

        return $this;
    }

    /**
     * @param \DateTime $startDate
     *
     * @return $this
     */
    public function applyTimeFilter(\DateTime $startDate)
    {
        $spec = self::expr()->andX(
            self::expr()->eq('ended', 0),
            self::expr()->orX(
                self::expr()->lt('endDate'),
                self::expr()->andX(
                    self::expr()->isNull('endDate'),
                    self::expr()->lt('startDate', $startDate)
                )
            )
        );

        $this->andWhere($spec);

        return $this;
    }
}

$spec = AdvertsWeShouldCloseSpecification::create()->applyTimeFilter(new \DateTime('-4weeks')); 

return $this->em->getRepository('RecruitmentBundle:Advert')->match($spec);
```

#### QueryBuilder

If you were about to do the same thing with only the QueryBuilder it would look like this:

```php
class AdvertRepository extends EntitySpecificationRepository
{
    protected function filterAdvertsWeShouldClose($qb)
    {
        return $qb
            ->andWhere('r.ended = 0')
            ->andWhere(
                $qb->expr()->orX(
                    'r.endDate < :now',
                    $qb->expr()->andX('r.endDate IS NULL', 'r.startDate < :timeLimit')
                )
            )
            ->setParameter('now', new \DateTime())
            ->setParameter('timeLimit', new \DateTime('-4weeks'))
        ;
    }

    protected function filterOwnedByCompany($qb, Company $company)
    {
        return $qb
            ->join('company', 'c')
            ->andWhere('c.id = :company_id')
            ->setParameter('company_id', $company->getId())
        ;
    }

    public function myQuery(Company $company)
    {
        $qb = $this->em->getRepository('RecruitmentBundle:Advert')->createQueryBuilder('r');
        $this->filterAdvertsWeShouldClose($qb)
        $this->filterOwnedByCompany($qb, $company)

        return $qb->getQuery()->getResult();
    }
}
```

# Configuration
Edit your doctrine settings to register default repository
```yaml
    orm:
        default_repository_class: VolodymyrKlymniuk\DoctrineSpecification\EntitySpecificationRepository
        auto_generate_proxy_classes: "%kernel.debug%"
        naming_strategy: doctrine.orm.naming_strategy.underscore
        auto_mapping: true
```

The issues with the QueryBuilder implementation are:

* You may only use the filters `filterOwnedByCompany` and `filterAdvertsWeShouldClose` inside AdvertRepository.
* You can not build a tree with And/Or/Not. Say that you want every Advert but not those owned by $company. There
  is no way to reuse `filterOwnedByCompany()` in that case.
* Different parts of the QueryBuilder filtering cannot be composed together, because of the way the API is created.
  Assume we have a filterGroupsForApi() call, there is no way to combine it with another call filterGroupsForPermissions().
  Instead reusing this code will lead to a third method filterGroupsForApiAndPermissions().