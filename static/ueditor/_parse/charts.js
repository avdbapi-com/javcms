/* UEditor 1.4.3 parser hardening: chart rendering stays as local table markup. */
UE.parse.register('charts',function(utils){
    utils.cssRule('chartsContainerHeight','.edui-chart-container { min-height:'+(this.chartContainerHeight||300)+'px}');
    // Highcharts and its config files are not bundled. Leave data-chart tables intact.
});
