<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use BenSampo\Embed\Rules\EmbeddableUrl;
use Cviebrock\EloquentSluggable\Services\SlugService;
use FFMpeg\Format\Video\X264;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Intervention\Image\Facades\Image;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Filters\Video\VideoFilters;
use Illuminate\Support\Facades\Storage;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Cloudinary\Api\Exception\ApiError;

// use Illuminate\Support\Facades\Storage;


class FrontPostController extends Controller
{
    public function show(Request $request, $name)
    {
        $page = 'showPost';
        // Cari user berdasarkan 'name'
        $user = User::where('name', $name)->first();

        if ($user) {
            $query = Post::where('user_id', $user->id);

            // Memproses pencarian jika parameter search_media disediakan
            if (request()->has('search_media') && request()->filled('search_media')) {
                $searchTerm = request('search_media');
                $query->where(function ($query) use ($searchTerm) {
                    $query->where('name', 'like', '%' . $searchTerm . '%')
                        ->orWhere('body', 'like', '%' . $searchTerm . '%');
                    // Sesuaikan dengan kolom-kolom lain yang ingin Anda cari
                });
            }

            // Ambil data post sesuai dengan query yang sudah diinisialisasi
            $data = $query->latest()->paginate(8);

            $show = User::where('name', $name)->first();
            return view('frontend.Post.front-post_show', compact('data', 'show', 'page', 'user'));
        } else {
            // Handle jika user tidak ditemukan
            echo "gagal";
        }
    }


    public function create($name)
    {
        $page = 'upload';
        $category = Category::get();
        $show = User::where('name', $name)->first();
        return view('frontend.Post.front_post_create', compact('category', 'show', 'page'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required',
            'file' => 'mimes:png,jpg,jpeg,mp4,mkv,webm,mp3,m4a,eps,psd,ai,aep,aepx,prproj,aup3,sesx,als,zip,rar|file|max:10240',
            'file2' => 'file|max:10240',
            'body' => 'required',
            'url=' => [new EmbeddableUrl],

        ], [
            'body.required' => 'Deskripsi harus diisikan',
            'file.mimes' => 'File harus berupa gambar, video, audio',
            'title.required' => 'Judul tidak boleh kosong',
        ]);
        $slug = SlugService::createSlug(Post::class, 'slug', $request->title);
        $store = new Post();
        $store->user_id = Auth::guard()->user()->id;
        $store->name = $request->title;
        $store->slug = $slug;
        $store->body = $request->body;
        $store->category_id = $request->category_menu;

        if ($request->file('file')) {
            $files = $request->file('file');
            $pilkat = $store->category_id;
            $ext = $files->getClientOriginalExtension();
            // Image
            if ($ext == 'png' || $ext == 'jpg' || $ext == 'jpeg') {
                $ext = $request->file('file')->extension();
                $final = 'photo' . time() . '.' . $ext;

                // Kompresi gambar
                $compressedImage = Image::make($files)->encode($ext, 10);
                $resolution = $compressedImage->height() . "x" . $compressedImage->width();

                $compressedImage->save(storage_path('app/public/uploads/photo/compress/') . $final);

                // menyimpan gambar asli
                $request->file('file')->move(storage_path('app/public/uploads/photo/'), $final);
                $store->resolution = $resolution;
                $store->file = $final;
            }
            // Video
            if ($ext == 'mp4' || $ext == 'mkv' || $ext == 'webm') {
                $ext = $request->file('file')->extension();
                $final = 'video' . time() . '.' . $ext;
                $request->file('file')->move(storage_path('app/public/uploads/video/'), $final);
                $store->file = $final;
            }
            // audio
            if ($ext == 'mp3' || $ext == 'm4a') {
                $ext = $request->file('file')->extension();
                $final = 'audio' . time() . '.' . $ext;
                $request->file('file')->move(storage_path('app/public/uploads/audio/'), $final);
                $store->file = $final;
            }
        }

        // Googledrive
        // file
        elseif ($request->has('linkgd')) {
            if (strpos($request->linkgd, 'preview') !== false) {
                $change_link = $request->linkgd;
                $store->urlgd = $change_link;
            } elseif (strpos($request->linkgd, 'view') !== false) {
                $change_link = str_replace('view', 'preview', $request->linkgd);
                $store->urlgd = $change_link;
            }
        }
        // file project
        if ($request->has('file2Link')) {
            if (strpos($request->file2Link, 'preview') !== false) {
                $change_link2 = $request->file2Link;
                $store->fpgd = $change_link2;
            } elseif (strpos($request->file2Link, 'view') !== false) {
                $change_link2 = str_replace('view', 'preview', $request->file2Link);
                $store->fpgd = $change_link2;
            }
        }
        // end Googledrive

        // YouTube
        if ($request->linkyt != null) {
            $pilkat = 4;
            $store->category_id = 4;
            $store->url = $request->linkyt;
        }
        //end YouTube

        // Input File Project
        if ($request->file('file2')) {
            $files2 = $request->file('file2');
            $ext2 = $files2->getClientOriginalExtension();
            $pilkat = $store->category_id;
            if ($ext2 == 'eps' || $ext2 == 'psd' || $ext2 == 'ai' || $ext2 == 'cdr') {
                // $ext2 = $request->file('file2')->extension();
                $final2 = 'rawphoto' . time() . '.' . $ext2;
                $request->file('file2')->move(storage_path('app/public/uploads/rawphoto'), $final2);
                $store->file_mentah = $final2;
            }

            if ($ext2 == 'aep' || $ext2 == 'aepx' || $ext2 == 'prproj') {
                // $ext2 = $request->file('file2')->getClientOriginalExtension();
                $final2 = 'rawvideo' . time() . '.' . $ext2;
                $request->file('file2')->move(storage_path('app/public/uploads/rawvideo'), $final2);
                $store->file_mentah = $final2;
            }

            if ($ext2 == 'aup3' || $ext2 == 'sesx' || $ext2 == 'als') {
                $final2 = 'rawaudio' . time() . '.' . $ext2;
                $request->file('file2')->move(storage_path('app/public/uploads/rawaudio'), $final2);
                $store->file_mentah = $final2;
            }

            if ($ext2 == 'rar' || $ext2 == 'zip') {
                if ($pilkat == 3) {
                    $final2 = 'rawphoto' . time() . '.' . $ext2;
                    $request->file('file2')->move(storage_path('app/public/uploads/rawphoto'), $final2);
                    $store->file_mentah = $final2;
                } elseif ($pilkat == 4) {
                    $final2 = 'rawvideo' . time() . '.' . $ext2;
                    $request->file('file2')->move(storage_path('app/public/uploads/rawvideo'), $final2);
                    $store->file_mentah = $final2;
                } elseif ($pilkat == 5) {
                    $final2 = 'rawaudio' . time() . '.' . $ext2;
                    $request->file('file2')->move(storage_path('app/public/uploads/rawaudio'), $final2);
                    $store->file_mentah = $final2;
                }
            }
        }
        // end Input Project


        $store->save();
        return redirect()->back()->with('success', 'Upload Success');
    }

    // public function delete($slug)
    // {
    //     $delete = Post::where('slug', $slug)->first();

    //     if ($delete->file == '') {
    //         $delete->delete();
    //         return redirect()->back()->with('success', 'berhasil di hapus');
    //     } else {
    //         if (file_exists(storage_path('app/public/uploads/photo/' . $delete->file))) {
    //             unlink(storage_path('app/public/uploads/photo/' . $delete->file));
    //             unlink(storage_path('app/public/uploads/photo/compress/' . $delete->file));
    //         } elseif (file_exists(storage_path('app/public/uploads/video/' . $delete->file))) {
    //             unlink(storage_path('app/public/uploads/video/' . $delete->file));
    //             unlink(storage_path('app/public/uploads/video/thumbnail/' . $delete->thumbnail));
    //             unlink(storage_path('app/public/uploads/video/720p/' . $delete->q720p));
    //             unlink(storage_path('app/public/uploads/video/480p/' . $delete->q480p));
    //             unlink(storage_path('app/public/uploads/video/360p/' . $delete->q360p));
    //         } elseif (file_exists(storage_path('app/public/uploads/audio/' . $delete->file))) {
    //             unlink(storage_path('app/public/uploads/audio/' . $delete->file));
    //         } elseif (file_exists(storage_path('app/public/uploads/rawphoto/' . $delete->file))) {
    //             unlink(storage_path('app/public/uploads/rawphoto/' . $delete->file_mentah));
    //         } elseif (file_exists(storage_path('app/public/uploads/rawvideo/' . $delete->file))) {
    //             unlink(storage_path('app/public/uploads/rawvideo/' . $delete->file_mentah));
    //             // } elseif (file_exists(storage_path('app/public/uploads/rawaudio/' . $delete->file))) {
    //             //     unlink(storage_path('app/public/uploads/rawaudio/' . $delete->file_mentah));
    //         }
    //         $delete->delete();
    //         return redirect()->back()->with('success', 'berhasil di hapus');
    //     }
    // }

    // public function view($id, $name)
    // {
    //     $view = Post::where('id', $id)->where('name', $name)->first();
    //     return view('detail', compact('view'));
    // }

    public function delete($slug)
    {
        $delete = Post::where('slug', $slug)->first();

        if (!$delete) {
            return redirect()->back()->with('error', 'Postingan tidak ditemukan.');
        }

        $filePaths = [];

        if ($delete->file != '') {
            $extension = pathinfo($delete->file, PATHINFO_EXTENSION);

            if (in_array($extension, ['jpg', 'png', 'jpeg', 'gif', 'eps', 'psd', 'ai'])) {
                $filePaths = array_merge($filePaths, [
                    'public/uploads/photo/' . $delete->file,
                    'public/uploads/photo/compress/' . $delete->file,
                    'public/uploads/rawphoto/' . $delete->file_mentah,
                ]);
            } elseif (in_array($extension, ['mp4', 'avi', 'mov', 'aep', 'aepx', 'prproj'])) {
                $filePaths = array_merge($filePaths, [
                    'public/uploads/video/' . $delete->file,
                    // 'public/uploads/video/thumbnail/' . $delete->thumbnail,
                    // 'public/uploads/video/720p/' . $delete->q720p,
                    // 'public/uploads/video/480p/' . $delete->q480p,
                    // 'public/uploads/video/360p/' . $delete->q360p,
                    'public/uploads/rawvideo/' . $delete->file_mentah,
                ]);
            } elseif (in_array($extension, ['mp3', 'm4a', 'wav', 'aup3', 'sesx', 'als'])) {
                $filePaths = array_merge($filePaths, [
                    'public/uploads/audio/' . $delete->file,
                    'public/uploads/rawaudio/' . $delete->file_mentah,
                ]);
            }
        }

        foreach ($filePaths as $filePath) {
            if (Storage::exists($filePath)) {
                Storage::delete($filePath);
            }
        }

        if ($delete->url) {
            $delete->url = null;
            if (Storage::exists('public/uploads/rawvideo/' . $delete->file_mentah)) {
                Storage::delete('public/uploads/rawvideo/' . $delete->file_mentah);
            }
        }

        if ($delete->urlgd) {
            $delete->urlgd = null;
            if ($delete->category_id == 3) {
                if (Storage::exists('public/uploads/rawphoto/' . $delete->file_mentah)) {
                    Storage::delete('public/uploads/rawphoto/' . $delete->file_mentah);
                }
            } elseif ($delete->category_id == 4) {
                if (Storage::exists('public/uploads/rawvideo/' . $delete->file_mentah)) {
                    Storage::delete('public/uploads/rawvideo/' . $delete->file_mentah);
                }
            } elseif ($delete->category_id == 5) {
                if (Storage::exists('public/uploads/rawaudio/' . $delete->file_mentah)) {
                    Storage::delete('public/uploads/rawaudio/' . $delete->file_mentah);
                }
            }
        }

        $delete->delete();
        return redirect()->back()->with('success', 'Postingan dan file terkait berhasil dihapus.');
    }


    public function edit($slug)
    {
        $page = 'editPost';
        $edit = Post::where('slug', $slug)->first();
        $post = Post::where('slug', $slug)->first();
        $category = Category::get();
        $show = User::findOrFail(Auth::id());
        return view('frontend.Post.front_post_edit', compact('edit', 'post', 'category', 'page', 'show'));
    }

    public function update(Request $request, $slug)
    {
        $update = Post::where('slug', $slug)->first();
        $user = User::where('id', $update->user_id)->first();
        $pilkat = $update->category_id;

        $request->validate([
            'file' => [
                'file',
                'max:10240',
                function ($attribute, $value, $fail) use ($pilkat) {
                    $ext = $value->getClientOriginalExtension();
                    $categoryFileTypes = [
                        3 => ['png', 'jpg', 'jpeg', 'eps', 'psd', 'ai'],
                        4 => ['mp4', 'mkv', 'webm'],
                        5 => ['mp3', 'm4a'],
                    ];

                    if (isset($categoryFileTypes[$pilkat])) {
                        if (!in_array($ext, $categoryFileTypes[$pilkat])) {
                            $fail('Tipe file yang diunggah tidak sesuai dengan kategori yang dipilih.');
                        }
                    } else {
                        $fail('Kategori yang dipilih tidak memiliki tipe file ini.');
                    }
                },
            ],
            'file2' => [
                'file',
                'max:10240',
                function ($attribute, $value, $fail) use ($pilkat) {
                    $ext = $value->getClientOriginalExtension();
                    $categoryFileTypes = [
                        3 => ['eps', 'psd', 'ai', 'zip', 'rar'],
                        4 => ['aep', 'aepx', 'prproj', 'zip', 'rar'],
                        5 => ['aup3', 'sesx', 'als', 'zip', 'rar'],
                    ];

                    if (isset($categoryFileTypes[$pilkat])) {
                        if (!in_array($ext, $categoryFileTypes[$pilkat])) {
                            $fail('Tipe file yang diunggah tidak sesuai dengan kategori yang dipilih.');
                        }
                    } else {
                        $fail('Kategori yang dipilih tidak memiliki tipe file ini.');
                    }
                },
            ],
        ], [
            'file.required' => 'Harus ada file yang dimasukkan.',
            'file.mimes' => 'File harus berupa gambar, video, atau audio.',
            'file2.mimes' => 'Tipe file yang diunggah tidak sesuai dengan file kedua.',
        ]);


        if ($request->hasFile('file')) {
            $files = $request->file('file');
            $ext = $files->getClientOriginalExtension();
            if (empty($update->file)) {
                if (file_exists(storage_path('app/public/uploads/photo/' . $update->file == ''))) {
                    if ($ext == 'png' || $ext == 'jpg' || $ext == 'jpeg') {
                        $ext = $request->file('file')->extension();
                        $final = 'photo' . time() . '.' . $ext;

                        // Kompresi gambar
                        $compressedImage = Image::make($files)->encode($ext, 10);
                        $resolution = $compressedImage->height() . "x" . $compressedImage->width();

                        $compressedImage->save(storage_path('app/public/uploads/photo/compress/') . $final);

                        // menyimpan gambar asli
                        $request->file('file')->move(storage_path('app/public/uploads/photo/'), $final);
                        $update->resolution = $resolution;
                        $update->file = $final;
                    }
                } elseif (file_exists(storage_path('app/public/uploads/video/' . $update->file == ''))) {
                    if ($ext == 'mp4' || $ext == 'mkv' || $ext == 'webm') {
                        $ext = $request->file('file')->extension();
                        $final = 'video' . time() . '.' . $ext;
                        $request->file('file')->move(storage_path('app/public/uploads/video/'), $final);
                        $update->file = $final;
                    }
                } elseif (file_exists(storage_path('app/public/uploads/audio/' . $update->file == ''))) {
                    if ($ext == 'mp3' || $ext == 'm4a') {
                        $ext = $request->file('file')->extension();
                        $final = 'audio' . time() . '.' . $ext;
                        $request->file('file')->move(storage_path('app/public/uploads/audio/'), $final);
                        $update->file = $final;
                    }
                }
            } elseif ($update->file) {
                if (file_exists(storage_path('app/public/uploads/photo/' . $update->file))) {
                    unlink(storage_path('app/public/uploads/photo/' . $update->file));
                    unlink(storage_path('app/public/uploads/photo/compress/' . $update->file));
                    if ($ext == 'png' || $ext == 'jpg' || $ext == 'jpeg') {
                        $ext = $request->file('file')->extension();
                        $final = 'photo' . time() . '.' . $ext;

                        // Kompresi gambar
                        $compressedImage = Image::make($files)->encode($ext, 10);
                        $resolution = $compressedImage->height() . "x" . $compressedImage->width();

                        $compressedImage->save(storage_path('app/public/uploads/photo/compress/') . $final);

                        // menyimpan gambar asli
                        $request->file('file')->move(storage_path('app/public/uploads/photo/'), $final);
                        $update->resolution = $resolution;
                        $update->file = $final;
                    }
                } elseif (file_exists(storage_path('app/public/uploads/video/' . $update->file))) {
                    unlink(storage_path('app/public/uploads/video/' . $update->file));
                    if ($ext == 'mp4' || $ext == 'mkv' || $ext == 'webm') {
                        $ext = $request->file('file')->extension();
                        $final = 'video' . time() . '.' . $ext;
                        $request->file('file')->move(storage_path('app/public/uploads/video/'), $final);
                        $update->file = $final;
                    }
                } elseif (file_exists(storage_path('app/public/uploads/audio/' . $update->file))) {
                    unlink(storage_path('app/public/uploads/audio/' . $update->file));
                    if ($ext == 'mp3' || $ext == 'm4a') {
                        $ext = $request->file('file')->extension();
                        $final = 'audio' . time() . '.' . $ext;
                        $request->file('file')->move(storage_path('app/public/uploads/audio/'), $final);
                        $update->file = $final;
                    }
                }
            }
        } elseif ($request->hasFile('file2')) {
            $files2 = $request->file('file2');
            $ext2 = $files2->getClientOriginalExtension();
            if (empty($update->file_mentah)) {
                if ($pilkat == 3 && file_exists(storage_path('app/public/uploads/rawphoto/' . $update->file_mentah == ''))) {
                    if ($ext2 == 'eps' || $ext2 == 'psd' || $ext2 == 'ai' || $ext2 == 'cdr' || $ext2 == 'zip' || $ext2 == 'rar') {
                        $final2 = 'rawphoto' . time() . '.' . $ext2;
                        $request->file('file2')->move(storage_path('app/public/uploads/rawphoto'), $final2);
                        $update->file_mentah = $final2;
                    }
                } elseif ($pilkat == 4 && storage_path('app/public/uploads/rawvideo/')) {
                    if ($ext2 == 'aep' || $ext2 == 'aepx' || $ext2 == 'prproj' || $ext2 == 'zip' || $ext2 == 'rar') {
                        $final2 = 'rawvideo' . time() . '.' . $ext2;
                        $request->file('file2')->move(storage_path('app/public/uploads/rawvideo'), $final2);
                        $update->file_mentah = $final2;
                    }
                } elseif ($pilkat == 5 && storage_path('app/public/uploads/rawaudio/')) {
                    if ($ext2 == 'aup3' || $ext2 == 'sesx' || $ext2 == 'als' || $ext2 == 'zip' || $ext2 == 'rar') {
                        $final2 = 'rawaudio' . time() . '.' . $ext2;
                        $request->file('file2')->move(storage_path('app/public/uploads/rawaudio'), $final2);
                        $update->file_mentah = $final2;
                    }
                }
            } elseif ($update->file_mentah) {
                if ($pilkat == 3 && file_exists(storage_path('app/public/uploads/rawphoto/' . $update->file_mentah))) {
                    unlink(storage_path('app/public/uploads/rawphoto/' . $update->file_mentah));
                    if ($ext2 == 'eps' || $ext2 == 'psd' || $ext2 == 'ai' || $ext2 == 'cdr' || $ext2 == 'zip' || $ext2 == 'rar') {
                        $final2 = 'rawphoto' . time() . '.' . $ext2;
                        $request->file('file2')->move(storage_path('app/public/uploads/rawphoto'), $final2);
                        $update->file_mentah = $final2;
                    }
                } elseif ($pilkat == 4 && file_exists(storage_path('app/public/uploads/rawvideo/' . $update->file_mentah))) {
                    unlink(storage_path('app/public/uploads/rawvideo/' . $update->file_mentah));
                    if ($ext2 == 'aep' || $ext2 == 'aepx' || $ext2 == 'prproj' || $ext2 == 'zip' || $ext2 == 'rar') {
                        $final2 = 'rawvideo' . time() . '.' . $ext2;
                        $request->file('file2')->move(storage_path('app/public/uploads/rawvideo'), $final2);
                        $update->file_mentah = $final2;
                    }
                } elseif ($pilkat == 5 && file_exists(storage_path('app/public/uploads/rawaudio/' . $update->file_mentah))) {
                    unlink(storage_path('app/public/uploads/rawaudio/' . $update->file_mentah));
                    if ($ext2 == 'aup3' || $ext2 == 'sesx' || $ext2 == 'als' || $ext2 == 'zip' || $ext2 == 'rar') {
                        $final2 = 'rawaudio' . time() . '.' . $ext2;
                        $request->file('file2')->move(storage_path('app/public/uploads/rawaudio'), $final2);
                        $update->file_mentah = $final2;
                    }
                }
            }
        }

        $request->validate([
            'title' => 'required',
            'body' => 'required',
            'url=' => [new EmbeddableUrl],

        ], [
            'body.required' => 'deskripsi harus diisikan',
            'title.required' => 'title tidak boleh kosong',
        ]);
        $slug = SlugService::createSlug(Post::class, 'slug', $request->title);
        $update->slug = $slug;
        $update->name = $request->title;
        $update->url = $request->linkyt;

        if (strpos($request->linkgd, 'preview') !== false) {
            $change_link = $request->linkgd;
            $update->urlgd = $change_link;
        } elseif (strpos($request->linkgd, 'view') !== false) {
            $change_link = str_replace('view', 'preview', $request->linkgd);
            $update->urlgd = $change_link;
        }

        if ($request->has('file2Link')) {
            if (strpos($request->file2Link, 'preview') !== false) {
                $change_link2 = $request->file2Link;
                $update->fpgd = $change_link2;
            } elseif (strpos($request->file2Link, 'view') !== false) {
                $change_link2 = str_replace('view', 'preview', $request->file2Link);
                $update->fpgd = $change_link2;
            }
        }

        $update->body = $request->body;
        $update->update();
        return redirect()->route('post_show', [$user->name])->with('success', 'berhasil di edit');
    }
}
