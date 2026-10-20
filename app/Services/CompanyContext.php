<?php

namespace App\Services;

use App\Models\Company;

class CompanyContext
{
    protected ?Company $company = null;

    protected bool $allCompanies = false;

    protected bool $noCompanyAccess = false;

    public function set(?Company $company): self
    {
        $this->company = $company;
        $this->allCompanies = false;
        $this->noCompanyAccess = false;

        return $this;
    }

    public function all(): self
    {
        $this->company = null;
        $this->allCompanies = true;
        $this->noCompanyAccess = false;

        return $this;
    }

    public function none(): self
    {
        $this->company = null;
        $this->allCompanies = false;
        $this->noCompanyAccess = true;

        return $this;
    }

    public function clear(): self
    {
        $this->company = null;
        $this->allCompanies = false;
        $this->noCompanyAccess = false;

        return $this;
    }

    /**
     * Runs $callback with $company open, then puts the context back exactly as
     * it was (a company, all, none or cleared).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function within(Company $company, callable $callback): mixed
    {
        [$before, $all, $none] = [$this->company, $this->allCompanies, $this->noCompanyAccess];

        $this->set($company);

        try {
            return $callback();
        } finally {
            $this->company = $before;
            $this->allCompanies = $all;
            $this->noCompanyAccess = $none;
        }
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function id(): ?int
    {
        return $this->company?->getKey();
    }

    public function hasCompany(): bool
    {
        return $this->company !== null;
    }

    public function isAllCompanies(): bool
    {
        return $this->allCompanies;
    }

    public function deniesCompanyAccess(): bool
    {
        return $this->noCompanyAccess;
    }
}
