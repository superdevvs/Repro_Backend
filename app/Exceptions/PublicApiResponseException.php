<?php

namespace App\Exceptions;

/** Only application-constructed, reviewed public envelopes may use this marker. */
class PublicApiResponseException extends \Illuminate\Http\Exceptions\HttpResponseException
{
}
