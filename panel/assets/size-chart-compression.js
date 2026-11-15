/* Exact decoded byte limit, shared by single downloads, saves and batch ZIP generation. */
function sizeChartDataUrlBytes(value) {
    const encoded=value.slice(value.indexOf(',')+1);
    return encoded.length*3/4-(encoded.endsWith('==')?2:encoded.endsWith('=')?1:0);
}
function compressSizeChartCanvas(source) {
    const maxBytes=400000;
    if(!source.width||!source.height)throw new Error('بوم جدول سایز خالی است.');
    let ratio=Math.min(1,3200/Math.max(source.width,source.height));
    for(let step=0;step<28;step++,ratio*=0.82) {
        const canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(source.width*ratio));canvas.height=Math.max(1,Math.round(source.height*ratio));
        const ctx=canvas.getContext('2d');if(!ctx)throw new Error('فشرده‌سازی تصویر در مرورگر در دسترس نیست.');
        ctx.fillStyle='#fff';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.drawImage(source,0,0,canvas.width,canvas.height);
        for(const quality of [0.90,0.82,0.74,0.66]) {
            const data=canvas.toDataURL('image/jpeg',quality);
            if(data.startsWith('data:image/jpeg;base64,')&&sizeChartDataUrlBytes(data)<=maxBytes)return data;
        }
        if(Math.min(canvas.width,canvas.height)<=128)break;
    }
    throw new Error('فشرده‌سازی تا سقف ۴۰۰ کیلوبایت ممکن نشد؛ خروجی ساخته نشد.');
}
