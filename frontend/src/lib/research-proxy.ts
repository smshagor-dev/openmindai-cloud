const paths:Record<string,RegExp>={
 GET:/^research\/(?:projects(?:\/\d+(?:\/sources\/\d+|\/revisions|\/events|\/(?:exports|artifacts)\/\d+\/download)?)?|invitations)$/,
 POST:/^research\/(?:projects(?:\/\d+\/(?:runs|control|components|sources|uploads|comments|members|approvals|experiments(?:\/\d+\/execute)?|exports))?|invitations\/\d+\/accept)$/,
 PUT:/^research\/projects\/\d+\/(?:settings|document|sources\/\d+|evidence\/\d+)$/,
 DELETE:/^research\/projects\/\d+\/members\/\d+$/,
};
export function researchRoute(method:string,path:string):boolean{return paths[method]?.test(path)||false;}
export function researchDownload(path:string):boolean{return /^research\/projects\/\d+\/(?:exports|artifacts)\/\d+\/download$/.test(path);}
