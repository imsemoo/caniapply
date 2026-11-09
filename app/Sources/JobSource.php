<?php

namespace App\Sources;

use App\Models\CandidateProfile;

interface JobSource
{
    /**
     * @return iterable<RawListing>
     *
     * @throws SourceFetchFailed when nothing usable could be retrieved
     */
    public function fetch(CandidateProfile $profile): iterable;

    /** @return string[] what could not be read during an otherwise successful fetch */
    public function partialFailures(): array;
}
