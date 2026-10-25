import os,re
from pathlib import Path
from fontTools.ttLib import TTFont
SCRIPTS={
 'bn':('RESEARCH_FONT_BENGALI','/usr/share/fonts/truetype/noto/NotoSerifBengali-Regular.ttf',r'[\u0980-\u09ff]+(?:[ \t]+[\u0980-\u09ff]+)*','Bengali','Noto Serif Bengali'),
 'hi':('RESEARCH_FONT_DEVANAGARI','/usr/share/fonts/truetype/noto/NotoSerifDevanagari-Regular.ttf',r'[\u0900-\u097f]+(?:[ \t]+[\u0900-\u097f]+)*','Devanagari','Noto Serif Devanagari'),
 'ar':('RESEARCH_FONT_ARABIC','/usr/share/fonts/truetype/noto/NotoNaskhArabic-Regular.ttf',r'[\u0600-\u06ff]+(?:[ \t]+[\u0600-\u06ff]+)*','Arabic','Noto Naskh Arabic'),
 'zh':('RESEARCH_FONT_CJK','/usr/share/fonts/opentype/noto/NotoSerifCJK-Regular.ttc',r'[\u3000-\u30ff\u3400-\u9fff\uac00-\ud7af]+','CJK','Noto Serif CJK SC'),
}
def prepare(tex,portable=False):
    before,sep,body=tex.partition('\\begin{document}')
    if not sep:raise ValueError('latex_document_missing')
    header=[];arabic=False
    for key,(env,default,pattern,script,family)in SCRIPTS.items():
        matches=list(re.finditer(pattern,body))
        if not matches:continue
        if portable:
            spec=family;options='' if script=='CJK' else 'Script='+script
        else:
            path=Path(os.environ.get(env,default))
            if not path.is_file():raise ValueError('missing_unicode_font_'+key)
            collection=path.suffix.lower()=='.ttc';index=2 if collection and key=='zh' else 0
            font=TTFont(path,fontNumber=index);cmap=font.getBestCmap();chars=set(''.join(m.group()for m in matches))
            if any(ord(c)not in cmap for c in chars if not c.isspace()):raise ValueError('unicode_font_missing_glyph_'+key)
            spec=path.name;options='Path={'+str(path.parent)+'/}'
            if collection:options+=',FontIndex='+str(index)
            if script!='CJK':options+=',Script='+script
        macro='rs'+key
        header.append('\\newfontfamily\\'+macro+'['+options+']{'+spec+'}')
        if key=='ar':
            arabic=True;body=re.sub(pattern,lambda m:'\\RL{{\\'+macro+' '+m.group()+'}}',body)
        else:body=re.sub(pattern,lambda m:'{\\'+macro+' '+m.group()+'}',body)
    if arabic:header.insert(0,'\\usepackage{bidi}')
    # Loaded after Pandoc's hyperref/bookmark packages, as bidi requires.
    return before+'\n'+'\n'.join(header)+'\n'+sep+body
