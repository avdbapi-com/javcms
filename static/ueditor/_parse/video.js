/* UEditor 1.4.3 parser hardening: use native media controls; no remote/plugin loader. */
UE.parse.register('vedio',function(){
    var video = this.root.getElementsByTagName('video'),
        audio = this.root.getElementsByTagName('audio');
    for (var i = 0; i < video.length; i++) { video[i].controls = true; }
    for (var j = 0; j < audio.length; j++) { audio[j].controls = true; }
});
