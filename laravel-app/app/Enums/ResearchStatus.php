<?php

namespace App\Enums;

enum ResearchStatus: string
{
    case Queued = 'queued';
    case Analyzing = 'analyzing_nonprofit';
    case FindingComparables = 'finding_comparables';
    case DiscoveringFunders = 'discovering_funders';
    case ResearchingProspects = 'researching_prospects';
    case ResearchingPeople = 'researching_people';
    case FindingRelationships = 'finding_relationships';
    case Verifying = 'verifying';
    case Scoring = 'scoring';
    case Completed = 'completed';
    case Failed = 'failed';
}
