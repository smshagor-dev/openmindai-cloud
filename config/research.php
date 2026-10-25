<?php
return [
 'model'=>env('RESEARCH_MODEL','openmindai-ultra'),
 'fallback_models'=>array_filter(explode(',',env('RESEARCH_FALLBACK_MODELS',''))),
 'embedding_model'=>env('RESEARCH_EMBEDDING_MODEL',''),
 'worker_url'=>env('RESEARCH_WORKER_URL',''), 'worker_key'=>env('RESEARCH_WORKER_KEY',''),
 'contact'=>env('RESEARCH_CONTACT_EMAIL',''), 'openalex_key'=>env('OPENALEX_API_KEY',''),
 'concurrency'=>3, 'attempts'=>3, 'max_tasks'=>64, 'lease_seconds'=>480,
 'token_budget'=>500000, 'source_limit'=>60, 'storage_bytes'=>104857600,
];
