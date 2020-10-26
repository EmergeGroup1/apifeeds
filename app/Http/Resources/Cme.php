<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class Cme extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        // this will return all the request in controller
        // return parent::toArray($request);

        // a customized return
        return [
            'id' => $this->id,
            'month' => $this->month,
            'last' => $this->last,
            'converted_last' => $this->converted_last,
            'basis' => $this->basis,
            'bidvalue' => $this->bidvalue

        ];
    }
}

