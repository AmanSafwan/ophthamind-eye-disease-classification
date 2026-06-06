<?php

class IcHelper
{
    public static function extractDOB($ic)
    {
        $year = substr($ic, 0, 2);
        $month = substr($ic, 2, 2);
        $day = substr($ic, 4, 2);

        $year = ($year > date('y')) ? "19$year" : "20$year";

        return "$year-$month-$day";
    }

    public static function calculateAge($ic)
    {
        $dob = self::extractDOB($ic);
        $birthDate = new DateTime($dob);
        $today = new DateTime();

        return $today->diff($birthDate)->y;
    }

    public static function getGender($ic)
    {
        $last = substr($ic, -1);
        return ($last % 2 == 0) ? "Female" : "Male";
    }
}