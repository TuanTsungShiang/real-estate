<?php

return [
    'open_data_url' => env(
        'REAL_ESTATE_OPEN_DATA_URL',
        'https://plvr.land.moi.gov.tw/Download?fileName=lvr_landcsv.zip&type=zip'
    ),

    /*
     * The default URL above only serves the current period, which is a few
     * months of data - not enough to see a trend. This one serves a full past
     * quarter, keyed by ROC year and quarter (114S1 = 2025 Q1).
     */
    'season_url' => env(
        'REAL_ESTATE_SEASON_URL',
        'https://plvr.land.moi.gov.tw/DownloadSeason?season={season}&fileName=lvr_landcsv.zip&type=zip'
    ),

    /*
     * A month with one or two sales has a meaningless median, and plotting it
     * makes the trend line jump. Below this many priced sales the chart breaks
     * the price line and shows volume only.
     */
    'trend_min_sample' => 5,

    /*
     * MOI splits the open data into one file per county, named by the county
     * letter code (a_lvr_land_a.csv -> 臺北市). The 鄉鎮市區 column only holds
     * the district, and district names repeat across counties (中正區 exists in
     * both 臺北市 and 基隆市), so the file prefix is the only way to tell them
     * apart. Listed north to south, which is also the dropdown order.
     */
    'county_codes' => [
        'a' => '臺北市',
        'f' => '新北市',
        'c' => '基隆市',
        'h' => '桃園市',
        'o' => '新竹市',
        'j' => '新竹縣',
        'k' => '苗栗縣',
        'b' => '臺中市',
        'n' => '彰化縣',
        'm' => '南投縣',
        'p' => '雲林縣',
        'i' => '嘉義市',
        'q' => '嘉義縣',
        'd' => '臺南市',
        'e' => '高雄市',
        't' => '屏東縣',
        'g' => '宜蘭縣',
        'u' => '花蓮縣',
        'v' => '臺東縣',
        'x' => '澎湖縣',
        'w' => '金門縣',
        'z' => '連江縣',

        // Counties merged into their neighbouring city, kept for older exports.
        'l' => '臺中縣',
        'r' => '臺南縣',
        's' => '高雄縣',
    ],
];
